<?php

namespace Utopia\Async\Parallel\Adapter;

use Utopia\Async\Exception\Adapter as AdapterException;
use Utopia\Async\Parallel\Adapter;
use Utopia\Async\Parallel\Pool\Parallel\Runtime as RuntimePool;

/**
 * ext-parallel Adapter.
 *
 * Parallel execution implementation using PHP's ext-parallel extension for true
 * multi-threaded parallel processing. Each thread runs in its own PHP runtime
 * with shared-nothing architecture.
 *
 * Uses a persistent pool of Runtime instances that are reused across multiple
 * task executions for optimal performance.
 *
 * Requires PHP compiled with ZTS (Zend Thread Safety) and ext-parallel installed.
 *
 * @internal Use Utopia\Async\Parallel facade instead
 * @package Utopia\Async\Parallel\Adapter
 */
class Parallel extends Adapter
{
    /**
     * Default persistent runtime pool
     */
    private static ?RuntimePool $pool = null;

    /**
     * Whether parallel support has been verified
     */
    private static bool $supportVerified = false;


    /**
     * Run a callable in a separate thread and return the result.
     *
     * @param callable $task The task to execute in parallel
     * @param mixed ...$args Arguments to pass to the task
     * @return mixed The result of the task execution
     * @throws AdapterException If ext-parallel support is not available
     * @throws \Throwable If the task throws an exception
     */
    public static function run(callable $task, mixed ...$args): mixed
    {
        static::checkSupport();

        // Wrap task with arguments
        $wrappedTask = function () use ($task, $args) {
            return $task(...$args);
        };

        $pool = static::getPool();
        $results = $pool->execute([$wrappedTask]);

        // For single task execution, re-throw any exception
        if ($pool->hasErrors()) {
            $errors = $pool->getLastErrors();
            if (isset($errors[0])) {
                $message = $errors[0]['message'] ?? 'Task execution failed';
                throw new \Exception($message);
            }
        }

        return $results[0] ?? null;
    }

    /**
     * Execute multiple tasks in parallel using a persistent runtime pool.
     *
     * @param array<callable> $tasks Array of callables to execute
     * @return array<mixed> Array of results corresponding to each task
     * @throws AdapterException If ext-parallel support is not available
     */
    public static function all(array $tasks): array
    {
        static::checkSupport();

        if (empty($tasks)) {
            return [];
        }

        // Convert callables to closures for ext-parallel
        $closureTasks = [];
        foreach ($tasks as $index => $task) {
            $closureTasks[$index] = $task instanceof \Closure ? $task : \Closure::fromCallable($task);
        }

        $pool = static::getPool();
        return $pool->execute($closureTasks);
    }

    /**
     * Map a function over an array in parallel using a persistent runtime pool.
     *
     * @param array<mixed> $items The array to map over
     * @param callable $callback Function to apply to each item: fn($item, $index) => mixed
     * @param int|null $workers Number of threads to use (null = auto-detect CPU cores)
     * @return array<mixed> Array of results in the same order as input
     * @throws AdapterException If ext-parallel support is not available
     */
    public static function map(array $items, callable $callback, ?int $workers = null): array
    {
        static::checkSupport();

        if (empty($items)) {
            return [];
        }

        $chunks = static::chunkItems($items, $workers);

        // Create tasks that process chunks
        $tasks = [];
        foreach ($chunks as $chunk) {
            $tasks[] = static function () use ($chunk, $callback): array {
                $results = [];
                foreach ($chunk as $index => $item) {
                    $results[$index] = $callback($item, $index);
                }
                return $results;
            };
        }

        $chunkResults = static::all($tasks);

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
     * @param array<mixed> $items The array to iterate over
     * @param callable $callback Function to apply to each item: fn($item, $index) => void
     * @param int|null $workers Number of threads to use (null = auto-detect CPU cores)
     * @return void
     * @throws AdapterException If ext-parallel support is not available
     */
    public static function forEach(array $items, callable $callback, ?int $workers = null): void
    {
        static::checkSupport();

        if (empty($items)) {
            return;
        }

        $chunks = static::chunkItems($items, $workers);

        $tasks = [];
        foreach ($chunks as $chunk) {
            $tasks[] = static function () use ($chunk, $callback): void {
                foreach ($chunk as $index => $item) {
                    $callback($item, $index);
                }
            };
        }

        static::all($tasks);
    }

    /**
     * Execute tasks in parallel with a maximum number of concurrent threads.
     *
     * @param array<callable> $tasks Array of callables to execute
     * @param int $maxConcurrency Maximum number of threads to run simultaneously
     * @return array<mixed> Array of results corresponding to each task
     * @throws AdapterException If ext-parallel support is not available
     */
    public static function pool(array $tasks, int $maxConcurrency): array
    {
        static::checkSupport();

        if (empty($tasks)) {
            return [];
        }

        // Create a limited pool for this operation
        $autoloader = static::findAutoloader();
        $limitedPool = new RuntimePool($maxConcurrency, $autoloader);

        try {
            // Convert callables to closures for ext-parallel
            $closureTasks = [];
            foreach ($tasks as $index => $task) {
                $closureTasks[$index] = $task instanceof \Closure ? $task : \Closure::fromCallable($task);
            }

            return $limitedPool->execute($closureTasks);
        } finally {
            $limitedPool->shutdown();
        }
    }

    /**
     * Get or create the default persistent runtime pool.
     *
     * @return RuntimePool The default runtime pool
     * @throws AdapterException If ext-parallel support is not available
     */
    public static function getPool(): RuntimePool
    {
        static::checkSupport();

        if (self::$pool === null || self::$pool->isShutdown()) {
            $autoloader = static::findAutoloader();
            self::$pool = new RuntimePool(static::getCPUCount(), $autoloader);
        }

        return self::$pool;
    }

    /**
     * Shutdown the default persistent runtime pool.
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
     * Find the Composer autoloader path.
     *
     * @return string|null Path to autoloader or null if not found
     */
    protected static function findAutoloader(): ?string
    {
        $vendorDir = \getenv('COMPOSER_VENDOR_DIR');
        if ($vendorDir !== false && \file_exists($vendorDir . '/autoload.php')) {
            return \realpath($vendorDir . '/autoload.php') ?: null;
        }

        $paths = [
            __DIR__ . '/../../../vendor/autoload.php',
            __DIR__ . '/../../../../vendor/autoload.php',
            __DIR__ . '/../../../../../vendor/autoload.php',
        ];

        foreach ($paths as $path) {
            $realPath = \realpath($path);
            if ($realPath !== false && \file_exists($realPath)) {
                return $realPath;
            }
        }

        $cwd = \getcwd();
        if ($cwd !== false) {
            $cwdPath = $cwd . '/vendor/autoload.php';
            if (\file_exists($cwdPath)) {
                return \realpath($cwdPath) ?: null;
            }
        }

        return null;
    }

    /**
     * Check if ext-parallel support is available.
     *
     * @return bool True if ext-parallel support is available
     */
    public static function isSupported(): bool
    {
        return \extension_loaded('parallel') && \class_exists(\parallel\Runtime::class);
    }

    /**
     * Check if ext-parallel support is available.
     *
     * @return void
     * @throws AdapterException If ext-parallel support is not available
     */
    protected static function checkSupport(): void
    {
        if (self::$supportVerified) {
            return;
        }

        if (!\extension_loaded('parallel')) {
            throw new AdapterException(
                'ext-parallel is not loaded. Please install the parallel extension: pecl install parallel. ' .
                'Note: Requires PHP compiled with ZTS (Zend Thread Safety).'
            );
        }

        if (!\class_exists(\parallel\Runtime::class)) {
            throw new AdapterException(
                'parallel\Runtime class not found. Please ensure ext-parallel is properly installed.'
            );
        }

        self::$supportVerified = true;
    }
}
