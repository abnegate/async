<?php

namespace Utopia\Async\Parallel\Adapter\Swoole;

use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Process as SwooleProcess;
use Utopia\Async\Exception;
use Utopia\Async\Exception\Adapter as AdapterException;
use Utopia\Async\Exception\Serialization as SerializationException;
use Utopia\Async\Parallel\Adapter;
use Utopia\Async\Parallel\Configuration;
use Utopia\Async\Parallel\Pool\Swoole\Process as ProcessPool;

/**
 * Process Pool Parallel Adapter.
 *
 * Parallel execution implementation using Swoole's Process Pool for efficient multi-process
 * parallel processing. Reuses worker processes across tasks to minimize the overhead of process
 * creation and destruction. Each task runs in an isolated memory space, ideal for CPU-intensive
 * operations that benefit from multi-core utilization.
 *
 * By default, uses a persistent pool that is reused across multiple calls for optimal performance.
 * You can also create explicit pools for fine-grained control.
 *
 * Requires Swoole extension with process support enabled.
 *
 * @internal Use Utopia\Async\Parallel facade instead
 * @package Utopia\Async\Parallel\Adapter\Swoole
 */
class Process extends Adapter
{
    /**
     * Default persistent worker pool
     */
    private static ?ProcessPool $pool = null;

    /**
     * Run a callable in a separate process and return the result.
     *
     * For single task execution, creates a temporary process. For multiple tasks,
     * use pool(), all(), or map() methods which leverage process pooling.
     *
     * @param callable $task The task to execute in parallel
     * @param mixed ...$args Arguments to pass to the task
     * @return mixed The result of the task execution
     * @throws \Exception If Coroutine process support is not available
     * @throws \Throwable If the task throws an exception
     */
    public static function run(callable $task, mixed ...$args): mixed
    {
        static::checkProcessSupport();

        $process = new SwooleProcess(function (SwooleProcess $worker) use ($task, $args) {
            try {
                $result = $task(...$args);
                $worker->write(\serialize([
                    'success' => true,
                    'result' => $result,
                ]));
            } catch (\Throwable $e) {
                $worker->write(\serialize(Exception::toArray($e)));
            }
        }, false, SOCK_STREAM, true);

        $process->start();
        $result = $process->read();
        SwooleProcess::wait();

        $data = @\unserialize(\is_string($result) ? $result : '', ['allowed_classes' => true]);
        if ($data === false) {
            throw new SerializationException('Failed to unserialize process result');
        }

        if (!\is_array($data)) {
            throw new SerializationException('Unserialized process result is not an array.');
        }

        if (Exception::isError($data)) {
            /** @var array<string, mixed> $data */
            throw Exception::fromArray($data);
        }

        return $data['result'] ?? null;
    }

    /**
     * Execute multiple tasks in parallel using a persistent process pool.
     *
     * Uses a persistent pool of reusable processes to execute tasks. The pool is created
     * on first use and reused across subsequent calls for optimal performance.
     *
     * @param array<callable> $tasks Array of callables to execute
     * @return array<mixed> Array of results corresponding to each task
     * @throws \Exception If Coroutine process support is not available
     */
    public static function all(array $tasks): array
    {
        static::checkProcessSupport();

        if (empty($tasks)) {
            return [];
        }

        $pool = static::getPool();
        return $pool->execute($tasks);
    }

    /**
     * Map a function over an array in parallel using a persistent process pool.
     *
     * Divides the input array into chunks and processes each chunk using a pool of reusable processes.
     * The number of processes defaults to the number of CPU cores.
     *
     * @param array<mixed> $items The array to map over
     * @param callable $callback Function to apply to each item: fn($item, $index) => mixed
     * @param int|null $workers Number of processes to use (null = auto-detect CPU cores)
     * @return array<mixed> Array of results in the same order as input
     * @throws \Exception If Coroutine process support is not available
     */
    public static function map(array $items, callable $callback, ?int $workers = null): array
    {
        static::checkProcessSupport();

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
     * @param int|null $workers Number of processes to use (null = auto-detect CPU cores / 2)
     * @return void
     */
    public static function forEach(array $items, callable $callback, ?int $workers = null): void
    {
        static::checkProcessSupport();

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
     * Execute tasks in parallel using a pool of reusable worker processes.
     *
     * Creates a fixed number of worker processes that remain alive and handle multiple tasks
     * via IPC (Inter-Process Communication). Workers pull tasks from the parent, process them,
     * and send results back, dramatically reducing process creation overhead.
     *
     * @param array<callable> $tasks Array of callables to execute
     * @param int $maxConcurrency Maximum number of processes in the pool
     * @return array<mixed> Array of results corresponding to each task
     * @throws \Exception If Coroutine process support is not available
     */
    public static function pool(array $tasks, int $maxConcurrency): array
    {
        static::checkProcessSupport();

        if (empty($tasks)) {
            return [];
        }

        $taskCount = \count($tasks);
        $results = \array_fill_keys(\array_keys($tasks), null);
        $workers = [];
        $taskQueue = \range(0, $taskCount - 1);
        $activeWorkers = [];

        for ($i = 0; $i < $maxConcurrency; $i++) {
            $worker = new SwooleProcess(function (SwooleProcess $worker) use ($tasks) {
                while (true) {
                    $message = $worker->read();

                    if ($message === 'STOP') {
                        break;
                    }

                    $taskData = @\unserialize(\is_string($message) ? $message : '', ['allowed_classes' => ['Closure', 'stdClass']]);
                    if ($taskData === false) {
                        continue;
                    }

                    if (!\is_array($taskData)) {
                        continue;
                    }

                    $index = $taskData['index'] ?? null;
                    if (!\is_int($index) && !\is_string($index)) {
                        continue;
                    }
                    if (!isset($tasks[$index])) {
                        continue;
                    }

                    $task = $tasks[$index];

                    try {
                        $result = $task();
                        $response = \serialize([
                            'index' => $index,
                            'success' => true,
                            'result' => $result,
                        ]);
                    } catch (\Throwable $e) {
                        $error = Exception::toArray($e);
                        $error['index'] = $index;
                        $response = \serialize($error);
                    }

                    $worker->write($response);
                }
            }, false, SOCK_STREAM, false);

            $worker->start();

            // Set timeout for the worker process to prevent blocking forever
            // Keep blocking mode to ensure complete messages are read
            $worker->setTimeout(0.01); // 10ms timeout

            $workers[$i] = $worker;
        }

        foreach ($workers as $workerId => $worker) {
            if (!empty($taskQueue)) {
                $taskIndex = \array_shift($taskQueue);
                $worker->write(\serialize(['index' => $taskIndex]));
                $activeWorkers[$workerId] = $taskIndex;
            }
        }

        $completed = 0;
        $startTime = \time();
        $lastProgressTime = $startTime;
        $lastCompleted = 0;

        while ($completed < $taskCount) {
            $currentTime = \time();

            if ($currentTime - $lastProgressTime > Configuration::getDeadlockDetectionInterval()) {
                if ($completed === $lastCompleted) {
                    throw new \RuntimeException(
                        \sprintf(
                            'Potential deadlock detected: no progress for %d seconds. Completed %d/%d tasks.',
                            Configuration::getDeadlockDetectionInterval(),
                            $completed,
                            $taskCount
                        )
                    );
                }
                $lastProgressTime = $currentTime;
                $lastCompleted = $completed;
            }

            if ($currentTime - $startTime > Configuration::getMaxTaskTimeoutSeconds()) {
                throw new \RuntimeException(
                    \sprintf(
                        'Task execution timeout: exceeded %d seconds. Completed %d/%d tasks.',
                        Configuration::getMaxTaskTimeoutSeconds(),
                        $completed,
                        $taskCount
                    )
                );
            }

            foreach ($workers as $workerId => $worker) {
                if (!isset($activeWorkers[$workerId])) {
                    continue;
                }

                $response = @$worker->read();

                if ($response === false || $response === '') {
                    continue;
                }

                $result = @\unserialize(\is_string($response) ? $response : '', ['allowed_classes' => true]);
                if (!\is_array($result) || !isset($result['index'])) {
                    continue;
                }

                $resultIndex = $result['index'];
                if (!\is_int($resultIndex) && !\is_string($resultIndex)) {
                    continue;
                }

                if (Exception::isError($result)) {
                    $results[$resultIndex] = null;
                } else {
                    $results[$resultIndex] = $result['result'] ?? null;
                }

                $completed++;
                unset($activeWorkers[$workerId]);

                if (!empty($taskQueue)) {
                    $taskIndex = array_shift($taskQueue);
                    $worker->write(\serialize(['index' => $taskIndex]));
                    $activeWorkers[$workerId] = $taskIndex;
                }
            }

            if (!empty($activeWorkers)) {
                // Use non-blocking sleep when in coroutine context
                if (SwooleCoroutine::getCid() > 0) {
                    SwooleCoroutine::sleep(Configuration::getWorkerSleepDurationUs() / 1000000);
                } else {
                    \usleep(Configuration::getWorkerSleepDurationUs());
                }
            }
        }

        // Shutdown workers gracefully and collect PIDs to wait for
        $pidsToWait = [];
        foreach ($workers as $worker) {
            $pidsToWait[$worker->pid] = true;
            $worker->write('STOP');
        }

        // Wait for our specific worker processes using non-blocking wait
        while (!empty($pidsToWait)) {
            $waitResult = SwooleProcess::wait(false); // Non-blocking
            if ($waitResult !== false && \is_array($waitResult) && isset($waitResult['pid'])) {
                $pid = $waitResult['pid'];
                if (\is_int($pid)) {
                    unset($pidsToWait[$pid]);
                }
            } else {
                // No child ready yet, use non-blocking sleep to avoid CPU spin
                if (SwooleCoroutine::getCid() > 0) {
                    SwooleCoroutine::sleep(0.001); // 1ms
                } else {
                    \usleep(1000);
                }
            }
        }

        return $results;
    }

    /**
     * Create a new explicit process pool with specified worker count.
     *
     * Useful when you need fine-grained control over pool size or want to manage
     * multiple independent pools.
     *
     * @param int $workers Number of worker processes in the pool
     * @return ProcessPool The created process pool
     * @throws AdapterException If process support is not available
     */
    public static function createPool(int $workers): ProcessPool
    {
        static::checkProcessSupport();
        return new ProcessPool($workers);
    }

    /**
     * Get or create the default persistent process pool.
     *
     * The default pool is lazily created with CPU count workers and reused across calls.
     *
     * @return ProcessPool The default process pool
     * @throws AdapterException If process support is not available
     */
    public static function getPool(): ProcessPool
    {
        static::checkProcessSupport();

        if (self::$pool === null || self::$pool->isShutdown() || !self::$pool->isHealthy()) {
            if (self::$pool !== null && !self::$pool->isShutdown()) {
                self::$pool->shutdown();
            }
            self::$pool = new ProcessPool(static::getCPUCount());
        }

        return self::$pool;
    }

    /**
     * Shutdown the default persistent process pool.
     *
     * This will gracefully terminate all worker processes in the default pool.
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
     * Check if Swoole process support is available.
     *
     * @return bool True if Swoole process support is available
     */
    public static function isSupported(): bool
    {
        return \extension_loaded('swoole') && \class_exists(SwooleProcess::class);
    }

    /**
     * Check if Swoole process support is available.
     *
     * @return void
     * @throws AdapterException If process support is not available
     */
    protected static function checkProcessSupport(): void
    {
        if (!\extension_loaded('swoole')) {
            throw new AdapterException('Swoole extension is not loaded. Please install the Swoole extension.');
        }
        if (!\class_exists(SwooleProcess::class)) {
            throw new AdapterException(
                'Swoole Process support is not available. Requires Swoole extension with process support.'
            );
        }
    }
}
