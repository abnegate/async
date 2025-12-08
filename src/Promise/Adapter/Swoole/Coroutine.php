<?php

namespace Utopia\Async\Promise\Adapter\Swoole;

use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Coroutine\Channel;
use Utopia\Async\Exception\Promise;
use Utopia\Async\Promise\Adapter;

/**
 * Coroutine Promise Adapter.
 *
 * Promise implementation using Swoole coroutines for true asynchronous execution.
 * Uses Swoole's go() function to run promises in separate coroutines and Channels
 * for efficient inter-coroutine communication in collection methods.
 *
 * @internal Use Utopia\Async\Promise facade instead
 * @package Utopia\Async\Promise\Adapter\Swoole
 */
class Coroutine extends Adapter
{
    /**
     * Check if Swoole coroutine support is available.
     *
     * @return bool True if Swoole coroutine support is available
     */
    public static function isSupported(): bool
    {
        return \extension_loaded('swoole');
    }

    /**
     * Create a new Thread promise instance.
     *
     * @param callable|null $executor Function with signature (callable $resolve, callable $reject): void
     */
    public function __construct(?callable $executor = null)
    {
        parent::__construct($executor);
    }

    /**
     * Execute the promise executor in a Thread coroutine.
     *
     * Wraps the executor in a go() call to run it asynchronously in a coroutine.
     * Automatically catches and rejects any exceptions thrown by the executor.
     *
     * @param callable $executor The executor function
     * @param callable $resolve The resolve callback
     * @param callable $reject The reject callback
     * @return void
     */
    protected function execute(
        callable $executor,
        callable $resolve,
        callable $reject
    ): void {
        \go(function () use ($executor, $resolve, $reject) {
            try {
                $executor($resolve, $reject);
            } catch (\Throwable $exception) {
                $reject($exception);
            }
        });
    }

    /**
     * Sleep for a short duration using Swoole coroutine sleep.
     *
     * Uses a 1ms sleep in coroutine context, or 10ms usleep otherwise.
     *
     * @return void
     */
    protected function sleep(): void
    {
        if (SwooleCoroutine::getCid() > 0) {
            SwooleCoroutine::sleep(self::COROUTINE_SLEEP_DURATION_S);
        } else {
            \usleep(self::SLEEP_DURATION_US);
        }
    }

    /**
     * Create a promise that resolves after a specified delay.
     *
     * Uses Thread's Coroutine::sleep() to implement the delay asynchronously
     * without blocking other coroutines.
     *
     * @param int $milliseconds The delay in milliseconds
     * @return static A promise that resolves to null after the delay
     */
    public static function delay(int $milliseconds): static
    {
        return self::create(function (callable $resolve) use ($milliseconds) {
            SwooleCoroutine::sleep($milliseconds / 1000);
            $resolve(null);
        });
    }

    /**
     * Wait for all promises to complete.
     *
     * Returns a promise that resolves when all input promises have resolved, or rejects
     * when any input promise rejects. Uses a Thread Channel to efficiently coordinate
     * between coroutines.
     *
     * @param array<Adapter> $promises Array of Promise instances
     * @return static A promise that resolves to an array of results (preserving input order)
     */
    public static function all(array $promises): static
    {
        return self::create(function (callable $resolve, callable $reject) use ($promises) {
            $ticks = \count($promises);

            $result = [];
            $error = null;
            $channel = new Channel($ticks);
            $key = 0;

            foreach ($promises as $promise) {
                $promise->then(function ($value) use ($key, &$result, $channel) {
                    $result[$key] = $value;
                    $channel->push(true);
                    return $value;
                }, function ($err) use ($channel, &$error) {
                    $channel->push(true);
                    if ($error === null) {
                        $error = $err;
                    }
                });
                $key++;
            }
            while ($ticks--) {
                $channel->pop();
            }
            $channel->close();

            if ($error !== null) {
                $reject($error);
                return;
            }

            $resolve($result);
        });
    }

    /**
     * Race multiple promises.
     *
     * Returns a promise that resolves or rejects as soon as one of the input promises
     * settles (fulfills or rejects). Uses a flag to ensure only the first settlement
     * is processed.
     *
     * @param array<Adapter> $promises Array of Promise instances
     * @return static A promise that settles with the first settled promise's result
     */
    public static function race(array $promises): static
    {
        return self::create(function (callable $resolve, callable $reject) use ($promises) {
            $settled = false;
            $channel = new Channel(1);

            foreach ($promises as $promise) {
                $promise->then(function ($value) use (&$settled, $channel, $resolve) {
                    if (!$settled) {
                        $settled = true;
                        $resolve($value);
                        $channel->push(true);
                    }
                    return $value;
                }, function ($err) use (&$settled, $channel, $reject) {
                    if (!$settled) {
                        $settled = true;
                        $reject($err);
                        $channel->push(true);
                    }
                });
            }

            $channel->pop();
            $channel->close();
        });
    }

    /**
     * Wait for all promises to settle.
     *
     * Returns a promise that resolves when all input promises have settled (either
     * fulfilled or rejected). Never rejects - instead returns an array of settlement
     * descriptors with status and value/reason for each promise.
     *
     * @param array<Adapter> $promises Array of Promise instances
     * @return static A promise that resolves to an array of settlement descriptors:
     *                ['status' => 'fulfilled'|'rejected', 'value' => mixed, 'reason' => mixed]
     */
    public static function allSettled(array $promises): static
    {
        return self::create(function (callable $resolve) use ($promises) {
            $ticks = \count($promises);
            $results = [];
            $channel = new Channel($ticks);
            $key = 0;

            foreach ($promises as $promise) {
                $promise->then(function ($value) use ($key, &$results, $channel) {
                    $results[$key] = ['status' => 'fulfilled', 'value' => $value];
                    $channel->push(true);
                    return $value;
                }, function ($err) use ($key, &$results, $channel) {
                    $results[$key] = ['status' => 'rejected', 'reason' => $err];
                    $channel->push(true);
                });
                $key++;
            }

            while ($ticks--) {
                $channel->pop();
            }
            $channel->close();

            $resolve($results);
        });
    }

    /**
     * Wait for the first fulfilled promise.
     *
     * Returns a promise that resolves when any of the input promises fulfills,
     * ignoring rejections. Only rejects if all input promises reject. Useful for
     * fallback scenarios where you want the first successful result.
     *
     * @param array<Adapter> $promises Array of Promise instances
     * @return static A promise that resolves with the first fulfilled value
     * @throws Promise If no promises are provided or if all promises reject
     */
    public static function any(array $promises): static
    {
        return self::create(function (callable $resolve, callable $reject) use ($promises) {
            $ticks = \count($promises);

            if ($ticks === 0) {
                $reject(new Promise('No promises provided to any()'));
                return;
            }

            $fulfilled = false;
            $errors = [];
            $channel = new Channel($ticks);
            $key = 0;

            foreach ($promises as $promise) {
                $promise->then(function ($value) use (&$fulfilled, $channel, $resolve) {
                    if (!$fulfilled) {
                        $fulfilled = true;
                        $resolve($value);
                    }
                    $channel->push(true);
                    return $value;
                }, function ($err) use ($key, &$errors, $channel) {
                    $errors[$key] = $err;
                    $channel->push(true);
                });
                $key++;
            }

            while ($ticks--) {
                $channel->pop();
            }

            $channel->close();

            if (!$fulfilled) {
                $reject(new Promise('All promises were rejected'));
            }
        });
    }
}
