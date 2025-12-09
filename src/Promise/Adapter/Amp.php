<?php

namespace Utopia\Async\Promise\Adapter;

use Utopia\Async\Exception\Adapter as AdapterException;
use Utopia\Async\Exception\Promise;
use Utopia\Async\Promise\Adapter;

/**
 * AMPHP Promise Adapter.
 *
 * Concurrent execution using amphp/amp event loop and fibers.
 * This provides single-threaded concurrency for async I/O operations.
 *
 * Requires amphp/amp package (v3+).
 *
 * @internal Use Utopia\Async\Promise facade instead
 * @package Utopia\Async\Promise\Adapter
 */
class Amp extends Adapter
{
    /**
     * Whether AMPHP support has been verified
     */
    private static bool $supportVerified = false;

    public function __construct(?callable $executor = null)
    {
        static::checkSupport();
        parent::__construct($executor);
    }

    /**
     * Execute the promise using AMPHP's event loop.
     *
     * @param callable $executor
     * @param callable $resolve
     * @param callable $reject
     * @return void
     */
    protected function execute(
        callable $executor,
        callable $resolve,
        callable $reject
    ): void {
        // Use AMPHP's async to run in the event loop
        \Revolt\EventLoop::queue(function () use ($executor, $resolve, $reject) {
            try {
                $executor($resolve, $reject);
            } catch (\Throwable $exception) {
                $reject($exception);
            }
        });
    }

    /**
     * Sleep using AMPHP's event loop delay.
     *
     * @return void
     */
    protected function sleep(): void
    {
        // Use Revolt event loop tick instead of blocking sleep
        \Revolt\EventLoop::run();
    }

    /**
     * Creates a promise that resolves after a specified delay.
     *
     * @param int $milliseconds
     * @return static
     */
    public static function delay(int $milliseconds): static
    {
        static::checkSupport();

        return self::create(function (callable $resolve) use ($milliseconds) {
            \Amp\delay($milliseconds / 1000);
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
        static::checkSupport();

        return self::create(function (callable $resolve, callable $reject) use ($promises) {
            if (empty($promises)) {
                $resolve([]);
                return;
            }

            $results = [];
            $remaining = \count($promises);
            $hasError = false;

            foreach ($promises as $key => $promise) {
                $promise->then(
                    function ($value) use ($key, &$results, &$remaining, $resolve, &$hasError) {
                        if ($hasError) {
                            return $value;
                        }
                        $results[$key] = $value;
                        $remaining--;
                        if ($remaining === 0) {
                            \ksort($results);
                            $resolve($results);
                        }
                        return $value;
                    },
                    function ($err) use ($reject, &$hasError) {
                        if (!$hasError) {
                            $hasError = true;
                            $reject($err);
                        }
                    }
                );
            }
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
        static::checkSupport();

        return self::create(function (callable $resolve, callable $reject) use ($promises) {
            $settled = false;

            foreach ($promises as $promise) {
                $promise->then(
                    function ($value) use (&$settled, $resolve) {
                        if (!$settled) {
                            $settled = true;
                            $resolve($value);
                        }
                        return $value;
                    },
                    function ($err) use (&$settled, $reject) {
                        if (!$settled) {
                            $settled = true;
                            $reject($err);
                        }
                    }
                );
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
        static::checkSupport();

        return self::create(function (callable $resolve) use ($promises) {
            if (empty($promises)) {
                $resolve([]);
                return;
            }

            $results = [];
            $remaining = \count($promises);

            foreach ($promises as $key => $promise) {
                $promise->then(
                    function ($value) use ($key, &$results, &$remaining, $resolve) {
                        $results[$key] = ['status' => 'fulfilled', 'value' => $value];
                        $remaining--;
                        if ($remaining === 0) {
                            \ksort($results);
                            $resolve($results);
                        }
                        return $value;
                    },
                    function ($err) use ($key, &$results, &$remaining, $resolve) {
                        $results[$key] = ['status' => 'rejected', 'reason' => $err];
                        $remaining--;
                        if ($remaining === 0) {
                            \ksort($results);
                            $resolve($results);
                        }
                    }
                );
            }
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
        static::checkSupport();

        return self::create(function (callable $resolve, callable $reject) use ($promises) {
            if (empty($promises)) {
                $reject(new Promise('No promises provided to any()'));
                return;
            }

            $errors = [];
            $remaining = \count($promises);
            $resolved = false;

            foreach ($promises as $key => $promise) {
                $promise->then(
                    function ($value) use (&$resolved, $resolve) {
                        if (!$resolved) {
                            $resolved = true;
                            $resolve($value);
                        }
                        return $value;
                    },
                    function ($err) use ($key, &$errors, &$remaining, &$resolved, $reject) {
                        if ($resolved) {
                            return;
                        }
                        $errors[$key] = $err;
                        $remaining--;
                        if ($remaining === 0) {
                            $reject(new Promise('All promises were rejected'));
                        }
                    }
                );
            }
        });
    }

    /**
     * Check if AMPHP support is available.
     *
     * @return bool True if AMPHP support is available
     */
    public static function isSupported(): bool
    {
        return \function_exists('Amp\async') && \class_exists(\Revolt\EventLoop::class);
    }

    /**
     * Check if AMPHP support is available.
     *
     * @return void
     * @throws AdapterException If AMPHP support is not available
     */
    protected static function checkSupport(): void
    {
        if (self::$supportVerified) {
            return;
        }

        if (!\function_exists('Amp\async')) {
            throw new AdapterException(
                'AMPHP is not available. Please install amphp/amp: composer require amphp/amp'
            );
        }

        if (!\class_exists(\Revolt\EventLoop::class)) {
            throw new AdapterException(
                'Revolt event loop is not available. Please install revolt/event-loop: composer require revolt/event-loop'
            );
        }

        self::$supportVerified = true;
    }
}
