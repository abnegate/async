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
     * Attach callbacks for promise resolution and/or rejection.
     *
     * @param callable|null $onFulfilled Called when promise fulfills
     * @param callable|null $onRejected Called when promise rejects
     * @return static A new promise
     */
    public function then(
        ?callable $onFulfilled = null,
        ?callable $onRejected = null
    ): static {
        return static::create(function (callable $resolve, callable $reject) use ($onFulfilled, $onRejected) {
            $this->waitWithBackoff();

            $callback = $this->isFulfilled() ? $onFulfilled : $onRejected;

            if (!\is_callable($callback)) {
                if ($this->isFulfilled()) {
                    $resolve($this->result);
                } else {
                    $reject($this->result);
                }
                return;
            }

            try {
                $resolve($callback($this->result));
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
        while ($this->isPending()) {
            $this->sleep();
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
     * Atomically settle the promise with a value and state.
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

        if ($state === self::STATE_FULFILLED) {
            $this->resolvePromise($value);
        } else {
            $this->result = $value;
            $this->state = $state;
        }
    }

    /**
     * The Promise Resolution Procedure per Promises/A+
     *
     * @param mixed $x The value to resolve with
     * @return void
     */
    private function resolvePromise(mixed $x): void
    {
        if ($x === $this) {
            $this->result = new \TypeError('A promise cannot be resolved with itself');
            $this->state = self::STATE_REJECTED;
            return;
        }

        if ($x instanceof self) {
            $this->adoptPromiseState($x);
            return;
        }

        if (\is_object($x) || $x instanceof \Closure) {
            try {
                if (\method_exists($x, 'then')) {
                    $then = [$x, 'then'];
                    if (\is_callable($then)) {
                        $this->handleThenable($x, $then);
                        return;
                    }
                }
            } catch (\Throwable $e) {
                $this->result = $e;
                $this->state = self::STATE_REJECTED;
                return;
            }
        }

        $this->result = $x;
        $this->state = self::STATE_FULFILLED;
    }

    /**
     * Adopt the state of another promise.
     *
     * @param self $promise
     * @return void
     */
    private function adoptPromiseState(self $promise): void
    {
        $resolved = false;

        $promise->then(
            function ($value) use (&$resolved) {
                if ($resolved) {
                    return;
                }
                $resolved = true;
                $this->resolvePromise($value);
            },
            function ($reason) use (&$resolved) {
                if ($resolved) {
                    return;
                }
                $resolved = true;
                $this->result = $reason;
                $this->state = self::STATE_REJECTED;
            }
        );

        while ($this->isPending()) {
            $this->sleep();
        }
    }

    /**
     * Handle a thenable object per Promises/A+
     *
     * @param object $thenable
     * @param callable $then
     * @return void
     */
    private function handleThenable(object $thenable, callable $then): void
    {
        $called = false;

        try {
            $then(
                function ($y) use (&$called) {
                    if ($called) {
                        return;
                    }
                    $called = true;
                    $this->resolvePromise($y);
                },
                function ($r) use (&$called) {
                    if ($called) {
                        return;
                    }
                    $called = true;
                    $this->result = $r;
                    $this->state = self::STATE_REJECTED;
                }
            );
        } catch (\Throwable $e) {
            if (!$called) {
                $this->result = $e;
                $this->state = self::STATE_REJECTED;
            }
        }

        while ($this->isPending()) {
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
