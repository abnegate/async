<?php

namespace Utopia\Async\Parallel;

/**
 * Abstract Parallel Adapter.
 *
 * Base class for parallel execution implementations. Provides the interface
 * for executing tasks in true parallel across multiple CPU cores.
 *
 * @internal Use Utopia\Async\Parallel facade instead
 * @package Utopia\Async\Parallel
 */
abstract class Adapter
{
    /**
     * Run a callable in parallel and return the result.
     *
     * Executes the provided callable in a separate execution context (thread, process, etc.)
     * and blocks until completion. The callable must be serializable, and any parameters
     * passed must also be serializable.
     *
     * @param callable $task The task to execute in parallel
     * @param mixed ...$args Arguments to pass to the task
     * @return mixed The result of the task execution
     * @throws \Throwable If the task throws an exception
     */
    abstract public static function run(callable $task, mixed ...$args): mixed;

    /**
     * Execute multiple tasks in parallel and return all results.
     *
     * Each task is executed in its own execution context. Returns an array of results
     * in the same order as the input tasks.
     *
     * @param array<callable> $tasks Array of callables to execute
     * @return array<mixed> Array of results corresponding to each task
     */
    abstract public static function all(array $tasks): array;

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
     */
    abstract public static function map(array $items, callable $callback, ?int $workers = null): array;

    /**
     * Execute a function for each item in an array in parallel.
     *
     * Similar to map() but doesn't collect return values. Useful for side-effect operations
     * where you don't need the results, such as writing to files, sending notifications, etc.
     * Divides the input array into chunks and processes each chunk in parallel.
     *
     * @param array<mixed> $items The array to iterate over
     * @param callable $callback Function to apply to each item: fn($item, $index) => void
     * @param int|null $workers Number of parallel workers to use (null = auto-detect CPU cores)
     * @return void
     */
    abstract public static function forEach(array $items, callable $callback, ?int $workers = null): void;

    /**
     * Execute tasks in parallel with a maximum number of concurrent workers.
     *
     * Similar to all(), but limits the number of workers running simultaneously.
     * Useful for controlling resource usage when dealing with many tasks.
     *
     * @param array<callable> $tasks Array of callables to execute
     * @param int $maxConcurrency Maximum number of workers to run simultaneously
     * @return array<mixed> Array of results corresponding to each task
     */
    abstract public static function pool(array $tasks, int $maxConcurrency): array;

    abstract public static function isSupported(): bool;

    /**
     * Shutdown any persistent resources (e.g., worker pools).
     *
     * @return void
     */
    public static function shutdown(): void
    {
        // Default: no-op. Adapters with persistent resources should override.
    }

    /**
     * Cached CPU count to avoid repeated system calls.
     *
     * @var int|null
     */
    private static ?int $cpuCount = null;

    /**
     * Get the number of CPU cores available.
     *
     * @return int Number of CPU cores
     */
    protected static function getCPUCount(): int
    {
        if (self::$cpuCount !== null) {
            return self::$cpuCount;
        }

        $count = 1;

        if (\function_exists('swoole_cpu_num')) {
            $count = \swoole_cpu_num();
        } else {
            // Try reading from /proc/cpuinfo
            if (PHP_OS_FAMILY === 'Linux' && \is_readable('/proc/cpuinfo')) {
                $cpuInfo = @\file_get_contents('/proc/cpuinfo');
                if ($cpuInfo !== false) {
                    $countTab = \substr_count($cpuInfo, 'processor\t:');
                    $countSpace = \substr_count($cpuInfo, 'processor :');
                    $count = \max($countTab, $countSpace, 1);
                }
            }

            // Fall back to system commands if /proc/cpuinfo detection failed
            if ($count <= 1) {
                if (PHP_OS_FAMILY === 'Windows') {
                    $process = @popen('wmic cpu get NumberOfCores', 'rb');
                    if ($process !== false) {
                        \fgets($process);
                        $cores = (int) \fgets($process);
                        \pclose($process);
                        $count = $cores ?: 1;
                    }
                } else {
                    $process = @popen('nproc', 'rb');
                    if ($process !== false) {
                        $cores = (int) \fgets($process);
                        \pclose($process);
                        $count = $cores ?: 1;
                    }
                }
            }
        }

        return self::$cpuCount = $count;
    }

    /**
     * Chunk items for parallel processing.
     *
     * Divides an array of items into optimal chunks for parallel processing based
     * on the number of workers. Each worker gets approximately equal-sized chunks.
     *
     * @param array<mixed> $items The array to chunk
     * @param int|null $workers Number of workers (null = auto-detect CPU cores)
     * @return array<array<mixed>> Array of chunks, each chunk preserving original keys
     */
    protected static function chunkItems(array $items, ?int $workers = null): array
    {
        if (empty($items)) {
            return [];
        }

        $workers = $workers ?? static::getCPUCount();
        $itemCount = \count($items);
        $actualWorkers = \min($workers, $itemCount);

        return \array_chunk($items, \max(1, (int)\ceil($itemCount / $actualWorkers)), true);
    }

    /**
     * Create a worker closure for map operations.
     *
     * Returns a worker function that applies a callback to each item in a chunk
     * and collects the results with their original indices preserved.
     *
     * @return callable(array<int|string, mixed>, callable): array<int|string, mixed> Worker function
     */
    protected static function createMapWorker(): callable
    {
        return function (array $chunk, callable $callback): array {
            $results = [];
            foreach ($chunk as $index => $item) {
                try {
                    $results[$index] = $callback($item, $index);
                } catch (\Throwable) {
                    $results[$index] = null;
                }
            }
            return $results;
        };
    }

    /**
     * Create a worker closure for forEach operations.
     *
     * Returns a worker function that applies a callback to each item in a chunk
     * without collecting results.
     *
     * @return callable(array<int|string, mixed>, callable): void Worker function
     */
    protected static function createForEachWorker(): callable
    {
        return function (array $chunk, callable $callback): void {
            foreach ($chunk as $index => $item) {
                $callback($item, $index);
            }
        };
    }

}
