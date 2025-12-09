<?php

namespace Utopia\Async;

use Utopia\Async\Exception\Adapter as AdapterException;
use Utopia\Async\Parallel\Adapter;
use Utopia\Async\Parallel\Adapter\AMPHP as AmpAdapter;
use Utopia\Async\Parallel\Adapter\Parallel as ParallelAdapter;
use Utopia\Async\Parallel\Adapter\React as ReactAdapter;
use Utopia\Async\Parallel\Adapter\Swoole\Process as SwooleProcessAdapter;
use Utopia\Async\Parallel\Adapter\Swoole\Thread as SwooleThreadAdapter;
use Utopia\Async\Parallel\Adapter\Sync as SyncAdapter;
use Utopia\Async\Parallel\Configuration;
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
     * 5. AMPHP (requires amphp/parallel)
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
        /** @var array<mixed> $result */
        $result = static::getAdapter()::all($tasks);
        return $result;
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
        /** @var array<mixed> $result */
        $result = static::getAdapter()::map($items, $callback, $workers);
        return $result;
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
        /** @var array<mixed> $result */
        $result = static::getAdapter()::pool($tasks, $maxConcurrency);
        return $result;
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
        /** @var ThreadPool|ProcessPool $pool */
        $pool = static::getAdapter()::createPool($workers);
        return $pool;
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

    /**
     * Get the maximum serialized data size in bytes.
     *
     * @return int
     */
    public static function getMaxSerializedSize(): int
    {
        return Configuration::getMaxSerializedSize();
    }

    /**
     * Set the maximum serialized data size in bytes.
     *
     * @param int $bytes Maximum size in bytes (default: 10MB)
     * @return void
     */
    public static function setMaxSerializedSize(int $bytes): void
    {
        Configuration::setMaxSerializedSize($bytes);
    }

    /**
     * Get the stream select timeout in microseconds.
     *
     * @return int
     */
    public static function getStreamSelectTimeoutUs(): int
    {
        return Configuration::getStreamSelectTimeoutUs();
    }

    /**
     * Set the stream select timeout in microseconds.
     *
     * @param int $microseconds Timeout in microseconds (default: 100ms)
     * @return void
     */
    public static function setStreamSelectTimeoutUs(int $microseconds): void
    {
        Configuration::setStreamSelectTimeoutUs($microseconds);
    }

    /**
     * Get the worker sleep duration in microseconds.
     *
     * @return int
     */
    public static function getWorkerSleepDurationUs(): int
    {
        return Configuration::getWorkerSleepDurationUs();
    }

    /**
     * Set the worker sleep duration in microseconds.
     *
     * @param int $microseconds Sleep duration in microseconds (default: 10ms)
     * @return void
     */
    public static function setWorkerSleepDurationUs(int $microseconds): void
    {
        Configuration::setWorkerSleepDurationUs($microseconds);
    }

    /**
     * Get the maximum task timeout in seconds.
     *
     * @return int
     */
    public static function getMaxTaskTimeoutSeconds(): int
    {
        return Configuration::getMaxTaskTimeoutSeconds();
    }

    /**
     * Set the maximum task timeout in seconds.
     *
     * @param int $seconds Timeout in seconds (default: 30)
     * @return void
     */
    public static function setMaxTaskTimeoutSeconds(int $seconds): void
    {
        Configuration::setMaxTaskTimeoutSeconds($seconds);
    }

    /**
     * Get the deadlock detection interval in seconds.
     *
     * @return int
     */
    public static function getDeadlockDetectionInterval(): int
    {
        return Configuration::getDeadlockDetectionInterval();
    }

    /**
     * Set the deadlock detection interval in seconds.
     *
     * @param int $seconds Interval in seconds (default: 5)
     * @return void
     */
    public static function setDeadlockDetectionInterval(int $seconds): void
    {
        Configuration::setDeadlockDetectionInterval($seconds);
    }

    /**
     * Get the memory threshold for garbage collection in bytes.
     *
     * @return int
     */
    public static function getMemoryThresholdForGc(): int
    {
        return Configuration::getMemoryThresholdForGc();
    }

    /**
     * Set the memory threshold for garbage collection in bytes.
     *
     * @param int $bytes Threshold in bytes (default: 50MB)
     * @return void
     */
    public static function setMemoryThresholdForGc(int $bytes): void
    {
        Configuration::setMemoryThresholdForGc($bytes);
    }

    /**
     * Get the garbage collection check interval.
     *
     * @return int
     */
    public static function getGcCheckInterval(): int
    {
        return Configuration::getGcCheckInterval();
    }

    /**
     * Set the garbage collection check interval.
     *
     * @param int $taskCount Number of completed tasks between GC checks (default: 10)
     * @return void
     */
    public static function setGcCheckInterval(int $taskCount): void
    {
        Configuration::setGcCheckInterval($taskCount);
    }

    /**
     * Reset all configuration options to their default values.
     *
     * @return void
     */
    public static function resetConfig(): void
    {
        Configuration::reset();
    }
}
