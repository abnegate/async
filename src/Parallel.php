<?php

namespace Utopia\Async;

use Utopia\Async\Exception\Adapter as AdapterException;
use Utopia\Async\Parallel\Adapter;
use Utopia\Async\Parallel\Adapter\Amp as AmpAdapter;
use Utopia\Async\Parallel\Adapter\Parallel as ParallelAdapter;
use Utopia\Async\Parallel\Adapter\React as ReactAdapter;
use Utopia\Async\Parallel\Adapter\Swoole\Process as SwooleProcessAdapter;
use Utopia\Async\Parallel\Adapter\Swoole\Thread as SwooleThreadAdapter;
use Utopia\Async\Parallel\Adapter\Sync as SyncAdapter;
use Utopia\Async\Parallel\Pool\Swoole\Process as ProcessPool;
use Utopia\Async\Parallel\Pool\Swoole\Thread as ThreadPool;

/**
 * Parallel execution facade.
 *
 * Provides parallel execution on multiple CPU cores. Unlike coroutines (which are
 * concurrent but run on a single thread), this class leverages OS threads/processes for
 * CPU-intensive tasks that can benefit from multi-core processing.
 *
 * Automatically selects the appropriate adapter based on available extensions.
 *
 * @package Utopia\Async
 */
class Parallel
{
    /**
     * The adapter class to use for parallel operations
     *
     * @var class-string<Adapter>
     */
    protected static string $adapter;

    /**
     * Set the adapter class to use for all Parallel operations.
     *
     * @param string $adapter Fully qualified class name of an Adapter implementation
     * @return void
     * @throws AdapterException If the adapter is not a valid parallel adapter class
     */
    public static function setAdapter(string $adapter): void
    {
        if (!\is_a($adapter, Adapter::class, true)) {
            throw new AdapterException('Adapter must be a valid parallel adapter implementation');
        }

        static::$adapter = $adapter;
    }

    /**
     * Get the current adapter class.
     *
     * Auto-detects the best available adapter with the following priority:
     * 1. Swoole Thread (requires Swoole >= 6.0 with thread support)
     * 2. ext-parallel (requires PHP with ZTS and ext-parallel)
     * 3. Swoole Process (requires Swoole extension)
     * 4. ReactPHP (requires react/child-process and react/event-loop)
     * 5. Amp (requires amphp/parallel)
     * 6. Sync (always available, sequential fallback)
     *
     * @return string
     */
    protected static function getAdapter(): string
    {
        if (!isset(static::$adapter)) {
            static::$adapter = static::detectAdapter();
        }

        return static::$adapter;
    }

    /**
     * Detect the best available parallel adapter.
     *
     * @return class-string<Adapter>
     */
    protected static function detectAdapter(): string
    {
        if (SwooleThreadAdapter::isSupported()) {
            return SwooleThreadAdapter::class;
        }
        if (ParallelAdapter::isSupported()) {
            return ParallelAdapter::class;
        }
        if (SwooleProcessAdapter::isSupported()) {
            return SwooleProcessAdapter::class;
        }
        if (ReactAdapter::isSupported()) {
            return ReactAdapter::class;
        }
        if (AmpAdapter::isSupported()) {
            return AmpAdapter::class;
        }

        return SyncAdapter::class;
    }

    /**
     * Run a callable in parallel and return the result.
     *
     * Executes the provided callable in a separate execution context and blocks until completion.
     * The callable must be serializable, and any parameters passed must also be serializable.
     *
     * @param callable $task The task to execute in parallel
     * @param mixed ...$args Arguments to pass to the task
     * @return mixed The result of the task execution
     * @throws \Throwable If the task throws an exception
     */
    public static function run(callable $task, mixed ...$args): mixed
    {
        return static::getAdapter()::run($task, ...$args);
    }

    /**
     * Execute multiple tasks in parallel and return all results.
     *
     * Each task is executed in its own execution context. Returns an array of results
     * in the same order as the input tasks.
     *
     * @param array<callable> $tasks Array of callables to execute
     * @return array<mixed> Array of results corresponding to each task
     * @throws AdapterException
     */
    public static function all(array $tasks): array
    {
        return static::getAdapter()::all($tasks);
    }

    /**
     * Map a function over an array in parallel.
     *
     * Divides the input array into chunks and processes each chunk in parallel.
     * The number of parallel workers defaults to the number of CPU cores.
     *
     * @param array<mixed> $items The array to map over
     * @param callable $callback Function to apply to each item: fn($item, $index) => mixed
     * @param int|null $workers Number of parallel workers to use (null = auto-detect CPU cores)
     * @return array<mixed> Array of results in the same order as input
     * @throws AdapterException
     */
    public static function map(array $items, callable $callback, ?int $workers = null): array
    {
        return static::getAdapter()::map($items, $callback, $workers);
    }

    /**
     * Execute a function for each item in an array in parallel.
     *
     * Similar to map() but doesn't collect return values. More memory-efficient
     * for side-effect operations where you don't need the results, such as writing
     * to files, sending notifications, or updating external systems.
     *
     * Divides the input array into chunks and processes each chunk in parallel.
     * The number of parallel workers defaults to the number of CPU cores.
     *
     * @param array<mixed> $items The array to iterate over
     * @param callable $callback Function to apply to each item: fn($item, $index) => void
     * @param int|null $workers Number of parallel workers to use (null = auto-detect CPU cores)
     * @return void
     * @throws AdapterException
     */
    public static function forEach(array $items, callable $callback, ?int $workers = null): void
    {
        static::getAdapter()::forEach($items, $callback, $workers);
    }

    /**
     * Execute tasks in parallel with a maximum number of concurrent workers.
     *
     * Similar to all(), but limits the number of workers running simultaneously.
     * Useful for controlling resource usage when dealing with many tasks.
     *
     * @param array<callable> $tasks Array of callables to execute
     * @param int $maxConcurrency Maximum number of workers to run simultaneously
     * @return array<mixed> Array of results corresponding to each task
     * @throws AdapterException
     */
    public static function pool(array $tasks, int $maxConcurrency): array
    {
        return static::getAdapter()::pool($tasks, $maxConcurrency);
    }

    /**
     * Create an explicit worker pool with specified worker count.
     *
     * @param int $workers Number of workers in the pool
     * @return ThreadPool|ProcessPool The created pool
     * @throws AdapterException
     */
    public static function createPool(int $workers): ThreadPool|ProcessPool
    {
        return static::getAdapter()::createPool($workers);
    }

    /**
     * Shutdown the default persistent worker pool.
     *
     * @return void
     */
    public static function shutdown(): void
    {
        static::getAdapter()::shutdown();
    }
}
