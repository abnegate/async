<?php

namespace Utopia\Async\Parallel\Adapter;

use Amp\Parallel\Context\ProcessContextFactory;
use Amp\Parallel\Worker\ContextWorkerFactory;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\WorkerPool;
use Utopia\Async\Exception\Adapter as AdapterException;
use Utopia\Async\Parallel\Adapter;
use Utopia\Async\Parallel\Adapter\Amp\Task;

/**
 * Amp Parallel Adapter.
 *
 * Parallel execution implementation using amphp/parallel for efficient multi-process
 * or multi-threaded parallel processing. Uses worker pools for task execution with
 * automatic serialization of data between parent and workers.
 *
 * Supports both process-based execution (default) and thread-based execution
 * when ext-parallel is available.
 *
 * Requires amphp/parallel package.
 *
 * @internal Use Utopia\Async\Parallel facade instead
 * @package Utopia\Async\Parallel\Adapter
 */
class Amp extends Adapter
{
    /**
     * Default worker pool instance
     */
    private static ?WorkerPool $pool = null;

    /**
     * Whether Amp support has been verified
     */
    private static bool $supportVerified = false;

    /**
     * Run a callable in a separate worker and return the result.
     *
     * @param callable $task The task to execute in parallel
     * @param mixed ...$args Arguments to pass to the task
     * @return mixed The result of the task execution
     * @throws AdapterException If Amp parallel support is not available
     * @throws \Throwable If the task throws an exception
     */
    public static function run(callable $task, mixed ...$args): mixed
    {
        static::checkSupport();

        $wrappedTask = static::wrapTask($task, $args);

        /** @var \Amp\Parallel\Worker\WorkerPool $pool */
        $pool = static::getPool();

        $execution = $pool->submit($wrappedTask);
        return $execution->await();
    }

    /**
     * Execute multiple tasks in parallel using a worker pool.
     *
     * @param array<callable> $tasks Array of callables to execute
     * @return array<mixed> Array of results corresponding to each task
     * @throws AdapterException If Amp parallel support is not available
     */
    public static function all(array $tasks): array
    {
        static::checkSupport();

        if (empty($tasks)) {
            return [];
        }

        /** @var \Amp\Parallel\Worker\WorkerPool $pool */
        $pool = static::getPool();

        $futures = [];
        foreach ($tasks as $index => $task) {
            $wrappedTask = static::wrapTask($task, []);
            $futures[$index] = $pool->submit($wrappedTask);
        }

        $results = [];
        foreach ($futures as $index => $future) {
            try {
                $results[$index] = $future->await();
            } catch (\Throwable $e) {
                $results[$index] = null;
            }
        }

        return $results;
    }

    /**
     * Map a function over an array in parallel using a worker pool.
     *
     * @param array<mixed> $items The array to map over
     * @param callable $callback Function to apply to each item: fn($item, $index) => mixed
     * @param int|null $workers Number of workers to use (null = auto-detect CPU cores)
     * @return array<mixed> Array of results in the same order as input
     * @throws AdapterException If Amp parallel support is not available
     */
    public static function map(array $items, callable $callback, ?int $workers = null): array
    {
        static::checkSupport();

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
     * @param int|null $workers Number of workers to use (null = auto-detect CPU cores)
     * @return void
     * @throws AdapterException If Amp parallel support is not available
     */
    public static function forEach(array $items, callable $callback, ?int $workers = null): void
    {
        static::checkSupport();

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

        static::all($tasks);
    }

    /**
     * Execute tasks in parallel with a maximum number of concurrent workers.
     *
     * @param array<callable> $tasks Array of callables to execute
     * @param int $maxConcurrency Maximum number of workers to run simultaneously
     * @return array<mixed> Array of results corresponding to each task
     * @throws AdapterException If Amp parallel support is not available
     * @throws \Throwable
     */
    public static function pool(array $tasks, int $maxConcurrency): array
    {
        static::checkSupport();

        if (empty($tasks)) {
            return [];
        }

        $limitedPool = static::createWorkerPool($maxConcurrency);

        $futures = [];
        foreach ($tasks as $index => $task) {
            $wrappedTask = static::wrapTask($task, []);
            $futures[$index] = $limitedPool->submit($wrappedTask);
        }

        $results = [];
        foreach ($futures as $index => $future) {
            try {
                $results[$index] = $future->await();
            } catch (\Throwable $e) {
                $results[$index] = null;
            }
        }

        $limitedPool->shutdown();

        return $results;
    }

    /**
     * Get or create the default worker pool.
     *
     * @return WorkerPool The worker pool instance
     * @throws AdapterException If Amp parallel support is not available
     */
    public static function getPool(): WorkerPool
    {
        static::checkSupport();

        if (self::$pool === null) {
            self::$pool = static::createWorkerPool(static::getCPUCount());
        }

        return self::$pool;
    }

    /**
     * Create a worker pool with process-based context.
     *
     * Uses ProcessContextFactory to avoid segfaults when ext-parallel
     * is loaded but buggy (common in containerized environments).
     *
     * @param int $limit Maximum number of workers
     * @return ContextWorkerPool The worker pool instance
     */
    protected static function createWorkerPool(int $limit): ContextWorkerPool
    {
        $processContextFactory = new ProcessContextFactory();
        $workerFactory = new ContextWorkerFactory(null, $processContextFactory);

        return new ContextWorkerPool($limit, $workerFactory);
    }

    /**
     * Shutdown the default worker pool.
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
     * Wrap a callable task for Amp parallel execution.
     *
     * @param callable $task The task to wrap
     * @param array<mixed> $args Arguments to pass to the task
     * @return Task The wrapped task
     */
    protected static function wrapTask(callable $task, array $args): Task
    {
        $serialized = \Opis\Closure\serialize($task);
        return new Task($serialized, $args);
    }

    /**
     * Check if Amp parallel support is available.
     *
     * @return bool True if Amp parallel support is available
     */
    public static function isSupported(): bool
    {
        return \function_exists('Amp\Parallel\Worker\workerPool');
    }

    /**
     * Check if Amp parallel support is available.
     *
     * @return void
     * @throws AdapterException If Amp parallel support is not available
     */
    protected static function checkSupport(): void
    {
        if (self::$supportVerified) {
            return;
        }

        if (!\function_exists('Amp\Parallel\Worker\workerPool')) {
            throw new AdapterException(
                'Amp parallel support is not available. Please install amphp/parallel: composer require amphp/parallel'
            );
        }

        self::$supportVerified = true;
    }
}
