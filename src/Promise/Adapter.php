<?php

namespace Utopia\Async\Promise;

use Utopia\Async\Exception\Timeout;

/**
 * Abstract Promise Adapter.
 *
 * Base class for promise implementations. Provides the core promise functionality
 * including state management, chaining (then/catch/finally), and collection methods
 * (all/race/allSettled/any). Concrete adapters must implement the execute() method
 * and collection methods to define how promises are executed asynchronously.
 *
 * @internal Use Utopia\Async\Promise facade instead
 * @phpstan-consistent-constructor
 * @package Utopia\Async\Promise
 */
abstract class Adapter
{
    /**
     * Promise state: pending (not yet resolved or rejected)
     */
    protected const STATE_PENDING = 1;

    /**
     * Promise state: fulfilled (successfully resolved)
     */
    protected const STATE_FULFILLED = 0;

    /**
     * Promise state: rejected (failed with error)
     */
    protected const STATE_REJECTED = -1;


    /**
     * Current state of the promise
     *
     * @var int
     */
    protected int $state = self::STATE_PENDING;

    /**
     * The resolved/rejected value
     *
     * @var mixed
     */
    private mixed $result;

    /**
     * Lock for atomic state transitions
     *
     * @var bool
     */
    private bool $settled = false;

    /**
     * Create a new promise instance.
     *
     * @param callable|null $executor Function with signature (callable $resolve, callable $reject): void
     */
    public function __construct(?callable $executor = null)
    {
        if (\is_null($executor)) {
            return;
        }
        $resolve = function (mixed $value): void {
            $this->settle($value, self::STATE_FULFILLED);
        };
        $reject = function (mixed $value): void {
            $this->settle($value, self::STATE_REJECTED);
        };
        $this->execute($executor, $resolve, $reject);
    }

    /**
     * Execute the promise executor function.
     *
     * This method must be implemented by concrete adapters to define how
     * the executor is run (e.g., in a coroutine, synchronously, etc.).
     *
     * @param callable $executor The executor function
     * @param callable $resolve The resolve callback
     * @param callable $reject The reject callback
     * @return void
     */
    abstract protected function execute(
        callable $executor,
        callable $resolve,
        callable $reject
    ): void;

    /**
     * Sleep for a short duration without blocking.
     *
     * This method must be implemented by concrete adapters to define how
     * to yield control while waiting (e.g., coroutine sleep, usleep, etc.).
     *
     * @return void
     */
    abstract protected function sleep(): void;

    /**
     * Create a new promise from the given callable.
     *
     * @param callable $promise
     * @return static
     */
    public static function create(callable $promise): static
    {
        return new static($promise);
    }

    /**
     * Resolve promise with given value.
     *
     * @param mixed $value
     * @return static
     */
    public static function resolve(mixed $value): static
    {
        return new static(function (callable $resolve) use ($value) {
            $resolve($value);
        });
    }

    /**
     * Rejects the promise with the given reason.
     *
     * @param mixed $value
     * @return static
     */
    public static function reject(mixed $value): static
    {
        return new static(function (callable $resolve, callable $reject) use ($value) {
            $reject($value);
        });
    }

    /**
     * Run a callable asynchronously and return a promise.
     *
     * The callable's return value becomes the resolved value. Any exceptions thrown
     * are automatically caught and converted to promise rejections.
     *
     * @param callable $callable The function to execute asynchronously
     * @return static A promise that resolves with the callable's return value
     */
    public static function async(callable $callable): static
    {
        return static::create(function (callable $resolve, callable $reject) use ($callable) {
            try {
                $result = $callable();
                $resolve($result);
            } catch (\Throwable $error) {
                $reject($error);
            }
        });
    }

    /**
     * Run a callable asynchronously and immediately await the result.
     *
     * Shorthand for async()->await(). Executes the callable in a promise and
     * blocks until it completes.
     *
     * @param callable $callable The function to execute asynchronously
     * @return mixed The result of the callable
     * @throws \Throwable If the callable throws or the promise is rejected
     */
    public static function run(callable $callable): mixed
    {
        return static::async($callable)->await();
    }

    /**
     * Creates a promise that resolves after a specified delay.
     *
     * @param int $milliseconds
     * @return static
     */
    abstract public static function delay(int $milliseconds): static;

    /**
     * Execute the promise.
     *
     * @param callable|null $onFulfilled
     * @param callable|null $onRejected
     * @return static
     */
    public function then(
        ?callable $onFulfilled = null,
        ?callable $onRejected = null
    ): static {
        if ($this->isRejected() && $onRejected === null) {
            return $this;
        }
        if ($this->isFulfilled() && $onFulfilled === null) {
            return $this;
        }
        return static::create(function (callable $resolve, callable $reject) use ($onFulfilled, $onRejected) {
            $this->waitWithBackoff();

            $callable = $this->isFulfilled() ? $onFulfilled : $onRejected;
            if (!\is_callable($callable)) {
                $resolve($this->result);
                return;
            }
            try {
                $resolve($callable($this->result));
            } catch (\Throwable $error) {
                $reject($error);
            }
        });
    }

    /**
     * Catch any exception thrown by the executor.
     *
     * @param callable $onRejected
     * @return static
     */
    public function catch(callable $onRejected): static
    {
        return $this->then(null, $onRejected);
    }

    /**
     * Execute a callback when the promise settles, regardless of outcome.
     *
     * @param callable $onFinally
     * @return static
     */
    public function finally(callable $onFinally): static
    {
        return $this->then(
            function ($value) use ($onFinally) {
                $onFinally();
                return $value;
            },
            function (mixed $reason) use ($onFinally) {
                $onFinally();
                if ($reason instanceof \Throwable) {
                    throw $reason;
                }
                throw new \RuntimeException(\is_string($reason) ? $reason : 'Promise rejected');
            }
        );
    }


    /**
     * Wait for the promise to resolve and return the result.
     *
     * @return mixed
     * @throws \Throwable
     */
    public function await(): mixed
    {
        $this->waitWithBackoff();

        if ($this->isRejected()) {
            if ($this->result instanceof \Throwable) {
                throw $this->result;
            }
            throw new \RuntimeException(\is_string($this->result) ? $this->result : 'Promise rejected');
        }

        return $this->result;
    }

    /**
     * Wait for promise with exponential backoff to reduce CPU usage.
     *
     * Starts with short sleeps and increases duration to reduce busy-waiting.
     *
     * @return void
     */
    private function waitWithBackoff(): void
    {
        $sleepDuration = Configuration::getSleepDurationUs();

        while ($this->isPending()) {
            $this->sleep();

            // Exponential backoff: double sleep duration up to maximum
            $sleepDuration = \min($sleepDuration * 2, Configuration::getMaxSleepDurationUs());
        }
    }

    /**
     * Wrap the promise with a timeout that rejects if not settled in time.
     *
     * @param int $milliseconds
     * @return static
     */
    public function timeout(int $milliseconds): static
    {
        return static::race([
            $this,
            static::delay($milliseconds)->then(function () use ($milliseconds) {
                throw new Timeout("Promise timed out after {$milliseconds}ms");
            })
        ]);
    }

    /**
     * Returns a promise that completes when all passed in promises complete.
     *
     * @param array<static> $promises
     * @return static
     */
    abstract public static function all(array $promises): static;

    /**
     * Returns a promise that resolves or rejects as soon as one of the promises settles.
     *
     * @param array<static> $promises
     * @return static
     */
    abstract public static function race(array $promises): static;

    /**
     * Returns a promise that resolves when all promises have settled (fulfilled or rejected).
     *
     * @param array<static> $promises
     * @return static Returns array of ['status' => 'fulfilled'|'rejected', 'value' => mixed, 'reason' => mixed]
     */
    abstract public static function allSettled(array $promises): static;

    /**
     * Returns a promise that resolves when any of the promises fulfills.
     * Rejects only if all promises reject.
     *
     * @param array<static> $promises
     * @return static
     */
    abstract public static function any(array $promises): static;


    /**
     * Atomically settle the promise with a value and state
     *
     * @param mixed $value
     * @param int $state
     * @return void
     */
    private function settle(mixed $value, int $state): void
    {
        // Atomic check-and-set to prevent multiple settlements (promise can only settle once)
        if ($this->settled) {
            return;
        }

        $this->settled = true;

        if (!$value instanceof self) {
            $this->result = $value;
            $this->state = $state;
            return;
        }

        // Handle promise chaining
        $resolved = false;
        $callable = function ($resolvedValue) use (&$resolved, $state) {
            $this->result = $resolvedValue;
            $this->state = $state;
            $resolved = true;
        };

        $value->then($callable, $callable);

        while (!$resolved) {
            $this->sleep();
        }
    }

    /**
     * Adapter is pending
     *
     * @return boolean
     */
    protected function isPending(): bool
    {
        return $this->state === static::STATE_PENDING;
    }

    /**
     * Adapter is fulfilled
     *
     * @return boolean
     */
    protected function isFulfilled(): bool
    {
        return $this->state === static::STATE_FULFILLED;
    }

    /**
     * Adapter is rejected
     *
     * @return boolean
     */
    protected function isRejected(): bool
    {
        return $this->state === static::STATE_REJECTED;
    }
}
