<?php

namespace Utopia\Async\Promise\Adapter;

use Utopia\Async\Exception\Adapter as AdapterException;
use Utopia\Async\Exception\Promise;
use Utopia\Async\Promise\Adapter;

/**
 * ReactPHP Promise Adapter.
 *
 * Concurrent execution using ReactPHP's event loop and promises.
 * This provides single-threaded concurrency for async I/O operations.
 *
 * Requires react/event-loop package.
 *
 * @internal Use Utopia\Async\Promise facade instead
 * @package Utopia\Async\Promise\Adapter
 */
class React extends Adapter
{
    /**
     * Whether React support has been verified
     */
    private static bool $supportVerified = false;

    public function __construct(?callable $executor = null)
    {
        static::checkSupport();
        parent::__construct($executor);
    }

    /**
     * Execute the promise using ReactPHP's event loop.
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
        \React\EventLoop\Loop::futureTick(function () use ($executor, $resolve, $reject) {
            try {
                $executor($resolve, $reject);
            } catch (\Throwable $exception) {
                $reject($exception);
            }
        });
    }

    /**
     * Sleep using ReactPHP's event loop tick.
     *
     * @return void
     */
    protected function sleep(): void
    {
        $loop = \React\EventLoop\Loop::get();

        $timer = $loop->addTimer(self::SLEEP_DURATION_US / 1000000, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();
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
            \React\EventLoop\Loop::addTimer($milliseconds / 1000, function () use ($resolve) {
                $resolve(null);
            });
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
     * Check if ReactPHP support is available.
     *
     * @return bool True if ReactPHP support is available
     */
    public static function isSupported(): bool
    {
        return \class_exists(\React\EventLoop\Loop::class);
    }

    /**
     * Check if ReactPHP support is available.
     *
     * @return void
     * @throws AdapterException If ReactPHP support is not available
     */
    protected static function checkSupport(): void
    {
        if (self::$supportVerified) {
            return;
        }

        if (!\class_exists(\React\EventLoop\Loop::class)) {
            throw new AdapterException(
                'ReactPHP event loop is not available. Please install react/event-loop: composer require react/event-loop'
            );
        }

        self::$supportVerified = true;
    }
}
