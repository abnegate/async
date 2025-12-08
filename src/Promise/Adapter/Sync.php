<?php

namespace Utopia\Async\Promise\Adapter;

use Utopia\Async\Exception\Promise;
use Utopia\Async\Promise\Adapter;

/**
 * Synchronous Promise Adapter (fallback).
 *
 * Executes promises synchronously when no async runtime is available.
 *
 * @internal Use Utopia\Async\Promise facade instead
 * @package Utopia\Async\Promise\Adapter
 */
class Sync extends Adapter
{
    /**
     * Sync adapter is always supported as it has no dependencies.
     *
     * @return bool Always returns true
     */
    public static function isSupported(): bool
    {
        return true;
    }

    public function __construct(?callable $executor = null)
    {
        parent::__construct($executor);
    }

    protected function execute(
        callable $executor,
        callable $resolve,
        callable $reject
    ): void {
        try {
            $executor($resolve, $reject);
        } catch (\Throwable $exception) {
            $reject($exception);
        }
    }

    /**
     * Sleep for a short duration using usleep.
     *
     * @return void
     */
    protected function sleep(): void
    {
        \usleep(self::SLEEP_DURATION_US);
    }

    /**
     * Creates a promise that resolves after a specified delay.
     *
     * @param int $milliseconds
     * @return static
     */
    public static function delay(int $milliseconds): static
    {
        return self::create(function (callable $resolve) use ($milliseconds) {
            usleep($milliseconds * 1000);
            $resolve(null);
        });
    }

    /**
     * Returns a promise that completes when all passed in promises complete.
     *
     * @param array<Adapter> $promises
     * @return static
     */
    public static function all(array $promises): static
    {
        return self::create(function (callable $resolve, callable $reject) use ($promises) {
            $result = [];
            $error = null;

            foreach ($promises as $key => $promise) {
                // Capture $key by value (not reference) to prevent all closures from sharing the same variable
                $promise->then(function ($value) use ($key, &$result) {
                    $result[$key] = $value;
                    return $value;
                }, function ($err) use (&$error) {
                    if ($error === null) {
                        $error = $err;
                    }
                });
            }

            if ($error !== null) {
                $reject($error);
                return;
            }

            $resolve($result);
        });
    }

    /**
     * Returns a promise that resolves or rejects as soon as one of the promises settles.
     *
     * @param array<Adapter> $promises
     * @return static
     */
    public static function race(array $promises): static
    {
        return self::create(function (callable $resolve, callable $reject) use ($promises) {
            $settled = false;

            foreach ($promises as $promise) {
                $promise->then(function ($value) use (&$settled, $resolve) {
                    if (!$settled) {
                        $settled = true;
                        $resolve($value);
                    }
                    return $value;
                }, function ($err) use (&$settled, $reject) {
                    if (!$settled) {
                        $settled = true;
                        $reject($err);
                    }
                });
            }
        });
    }

    /**
     * Returns a promise that resolves when all promises have settled (fulfilled or rejected).
     *
     * @param array<Adapter> $promises
     * @return static
     */
    public static function allSettled(array $promises): static
    {
        return self::create(function (callable $resolve) use ($promises) {
            $results = [];

            foreach ($promises as $key => $promise) {
                try {
                    $value = $promise->await();
                    $results[$key] = ['status' => 'fulfilled', 'value' => $value];
                } catch (\Throwable $err) {
                    $results[$key] = ['status' => 'rejected', 'reason' => $err];
                }
            }

            $resolve($results);
        });
    }

    /**
     * Returns a promise that resolves when any of the promises fulfills.
     * Rejects only if all promises reject.
     *
     * @param array<Adapter> $promises
     * @return static
     */
    public static function any(array $promises): static
    {
        return self::create(function (callable $resolve, callable $reject) use ($promises) {
            if (empty($promises)) {
                $reject(new Promise('No promises provided to any()'));
                return;
            }

            $errors = [];

            foreach ($promises as $key => $promise) {
                try {
                    $value = $promise->await();
                    $resolve($value);
                    return;
                } catch (\Throwable $err) {
                    $errors[$key] = $err;
                }
            }

            $reject(new Promise('All promises were rejected'));
        });
    }
}
