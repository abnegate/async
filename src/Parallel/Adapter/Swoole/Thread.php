<?php

namespace Utopia\Async\Parallel\Adapter\Swoole;

use Swoole\Process as SwooleProcess;
use Swoole\Thread as SwooleThread;
use Utopia\Async\Exception\Adapter as AdapterException;
use Utopia\Async\Parallel\Adapter;
use Utopia\Async\Parallel\Pool\Swoole\Thread as ThreadPool;

/**
 * Thread Pool Parallel Adapter.
 *
 * Parallel execution implementation using Swoole's Thread Pool for efficient multi-threaded
 * parallel processing. Reuses worker threads across tasks to minimize the overhead of thread
 * creation and destruction. Threads share memory space, making this ideal for CPU-intensive
 * operations that benefit from multi-core utilization with lower overhead than processes.
 *
 * Uses a persistent pool that is automatically managed and reused across multiple calls.
 * Note: Only one thread pool per process is supported due to Swoole threading limitations.
 *
 * Requires Swoole >= 6.0 with thread support enabled.
 *
 * @internal Use Utopia\Async\Parallel facade instead
 * @package Utopia\Async\Parallel\Adapter\Swoole
 */
class Thread extends Adapter
{
    /**
     * Default persistent worker pool
     */
    private static ?ThreadPool $pool = null;

    /**
     * Whether shutdown handler has been registered
     */
    private static bool $shutdownRegistered = false;

    /**
     * Path to the thread worker script
     */
    private static ?string $workerScript = null;

    /**
     * Whether thread support has been verified
     */
    private static bool $threadSupportVerified = false;

    /**
     * Run a callable in a separate thread and return the result.
     *
     * For single task execution, uses the thread pool. For multiple tasks,
     * use pool(), all(), or map() methods which leverage thread pooling.
     *
     * @param callable $task The task to execute in parallel
     * @param mixed ...$args Arguments to pass to the task
     * @return mixed The result of the task execution
     * @throws AdapterException If thread support is not available
     * @throws \Exception If the task throws an exception
     */
    public static function run(callable $task, mixed ...$args): mixed
    {
        static::checkThreadSupport();

        $wrappedTask = function () use ($task, $args) {
            return $task(...$args);
        };

        $pool = static::getPool();
        $results = $pool->execute([$wrappedTask]);

        if ($pool->hasErrors()) {
            $errors = $pool->getLastErrors();
            if (isset($errors[0])) {
                $errorInfo = $errors[0];
                $message = $errorInfo['message'];
                throw new \Exception($message);
            }
        }

        return $results[0] ?? null;
    }

    /**
     * Execute multiple tasks in parallel using a persistent thread pool.
     *
     * Uses a persistent pool of reusable threads to execute tasks. The pool is created
     * on first use and reused across subsequent calls for optimal performance.
     *
     * @param array<callable> $tasks Array of callables to execute
     * @return array<mixed> Array of results corresponding to each task
     * @throws AdapterException If thread support is not available
     */
    public static function all(array $tasks): array
    {
        static::checkThreadSupport();

        if (empty($tasks)) {
            return [];
        }

        $pool = static::getPool();
        return $pool->execute($tasks);
    }

    /**
     * Map a function over an array in parallel using a persistent thread pool.
     *
     * Divides the input array into chunks and processes each chunk using a pool of reusable threads.
     * The number of threads defaults to the number of CPU cores.
     *
     * @param array<mixed> $items The array to map over
     * @param callable $callback Function to apply to each item: fn($item, $index) => mixed
     * @param int|null $workers Number of threads to use (null = auto-detect CPU cores)
     * @return array<mixed> Array of results in the same order as input
     * @throws AdapterException If thread support is not available
     */
    public static function map(array $items, callable $callback, ?int $workers = null): array
    {
        static::checkThreadSupport();

        if (empty($items)) {
            return [];
        }

        $chunks = static::chunkItems($items, $workers);
        $worker = static::createMapWorker();

        $tasks = [];
        foreach ($chunks as $chunk) {
            $tasks[] = function () use ($worker, $chunk, $callback) {
                return $worker($chunk, $callback);
            };
        }

        $pool = static::getPool();
        $chunkResults = $pool->execute($tasks);

        $allResults = [];
        foreach ($chunkResults as $chunk) {
            if (\is_array($chunk)) {
                foreach ($chunk as $index => $value) {
                    $allResults[$index] = $value;
                }
            }
        }

        return $allResults;
    }

    /**
     * Execute a function for each item in an array in parallel.
     *
     * Similar to map() but doesn't collect return values. More memory-efficient
     * for side-effect operations where results aren't needed.
     *
     * @param array<mixed> $items The array to iterate over
     * @param callable $callback Function to apply to each item: fn($item, $index) => void
     * @param int|null $workers Number of threads to use (null = auto-detect CPU cores)
     * @return void
     */
    public static function forEach(array $items, callable $callback, ?int $workers = null): void
    {
        static::checkThreadSupport();

        if (empty($items)) {
            return;
        }

        $chunks = static::chunkItems($items, $workers);
        $worker = static::createForEachWorker();

        $tasks = [];
        foreach ($chunks as $chunk) {
            $tasks[] = function () use ($worker, $chunk, $callback): void {
                $worker($chunk, $callback);
            };
        }

        $pool = static::getPool();
        $pool->execute($tasks);
    }

    /**
     * Execute tasks in parallel with a maximum number of concurrent workers.
     *
     * Similar to all(), but processes tasks in batches with limited concurrency.
     * Useful for controlling resource usage when dealing with many tasks.
     *
     * @param array<callable> $tasks Array of callables to execute
     * @param int $maxConcurrency Maximum number of workers to run simultaneously
     * @return array<mixed> Array of results corresponding to each task
     * @throws AdapterException If thread support is not available
     */
    public static function pool(array $tasks, int $maxConcurrency): array
    {
        static::checkThreadSupport();

        if (empty($tasks)) {
            return [];
        }

        $batches = \array_chunk($tasks, \max(1, $maxConcurrency), true);
        $results = [];

        foreach ($batches as $batch) {
            $batchResults = static::all($batch);
            foreach ($batchResults as $index => $result) {
                $results[$index] = $result;
            }
        }

        return $results;
    }

    /**
     * Get or create the default persistent thread pool.
     *
     * The default pool is lazily created with CPU count workers and reused across calls.
     * If the pool becomes unhealthy (workers died), it will be recreated.
     * A shutdown handler is automatically registered to clean up the pool on script termination.
     *
     * @return ThreadPool The default thread pool
     * @throws AdapterException If thread support is not available
     */
    public static function getPool(): ThreadPool
    {
        static::checkThreadSupport();

        if (self::$pool === null || self::$pool->isShutdown() || !self::$pool->isHealthy()) {
            if (self::$pool !== null && !self::$pool->isShutdown()) {
                self::$pool->shutdown();
            }

            $pool = new ThreadPool(static::getCPUCount(), static::getWorkerScript());
            self::$pool = $pool;

            if (!self::$shutdownRegistered) {
                \register_shutdown_function([self::class, 'shutdown']);
                if (\Swoole\Coroutine::getCid() > 0) {
                    SwooleProcess::signal(SIGTERM, Thread::shutdown(...));
                    SwooleProcess::signal(SIGINT, Thread::shutdown(...));
                }
                self::$shutdownRegistered = true;
            }

            return $pool;
        }

        return self::$pool;
    }

    /**
     * Shutdown the default persistent thread pool.
     *
     * This will gracefully terminate all worker threads in the default pool.
     * The pool will be recreated on the next call to all() or map().
     *
     * @return void
     */
    public static function shutdown(): void
    {
        if (self::$pool !== null) {
            self::$pool->shutdown();
            self::$pool = null;
        }
    }

    /**
     * Get the path to the thread worker script.
     *
     * @return string Path to the worker script
     */
    protected static function getWorkerScript(): string
    {
        if (self::$workerScript === null) {
            self::$workerScript = __DIR__ . '/../../Worker/thread_worker.php';
        }

        return self::$workerScript;
    }

    /**
     * Set a custom worker script path.
     *
     * @param string $path Path to the worker script
     * @return void
     */
    public static function setWorkerScript(string $path): void
    {
        self::$workerScript = $path;
    }

    /**
     * Check if Swoole thread support is available.
     *
     * @return bool True if Swoole thread support is available
     */
    public static function isSupported(): bool
    {
        return \extension_loaded('swoole') && \class_exists(SwooleThread::class);
    }

    /**
     * Check if Swoole thread support is available.
     *
     * Uses caching to avoid redundant extension_loaded and class_exists checks.
     *
     * @return void
     * @throws AdapterException If thread support is not available
     */
    protected static function checkThreadSupport(): void
    {
        if (self::$threadSupportVerified) {
            return;
        }

        if (!\extension_loaded('swoole')) {
            throw new AdapterException('Swoole extension is not loaded. Please install the Swoole extension.');
        }
        if (!\class_exists(SwooleThread::class)) {
            throw new AdapterException(
                'Swoole Thread support is not available. Requires Swoole >= 6.0 with thread support enabled.'
            );
        }

        self::$threadSupportVerified = true;
    }
}
