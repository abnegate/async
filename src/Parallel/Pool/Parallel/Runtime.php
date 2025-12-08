<?php

namespace Utopia\Async\Parallel\Pool\Parallel;

use Utopia\Async\Parallel\Configuration;

/**
 * Persistent Runtime Pool for ext-parallel.
 *
 * Maintains a pool of long-lived Runtime instances that can be reused across
 * multiple task batches, eliminating the overhead of runtime creation/destruction.
 *
 * Requires PHP compiled with ZTS and ext-parallel installed.
 *
 * @internal Use Utopia\Async\Parallel facade instead
 * @package Utopia\Async\Parallel\Pool\Parallel
 */
class Runtime
{
    /**
     * Pool of available Runtime instances
     *
     * @var array<\parallel\Runtime>
     */
    private array $available = [];


    /**
     * Number of workers in the pool
     */
    private int $workerCount;

    /**
     * Path to the autoloader for bootstrapping
     */
    private ?string $autoloader;

    /**
     * Whether the pool has been shut down
     */
    private bool $shutdown = false;

    /**
     * Stored error information from last execution.
     *
     * @var array<int, array{message: string}>
     */
    private array $lastErrors = [];

    /**
     * Create a new runtime pool.
     *
     * @param int $workerCount Number of worker runtimes to create
     * @param string|null $autoloader Path to autoloader for bootstrapping (optional)
     * @throws \InvalidArgumentException If workerCount is invalid
     */
    public function __construct(int $workerCount, ?string $autoloader = null)
    {
        if ($workerCount <= 0) {
            throw new \InvalidArgumentException(
                \sprintf('Worker count must be greater than 0, got %d', $workerCount)
            );
        }

        $this->workerCount = $workerCount;
        $this->autoloader = $autoloader;
        $this->initializeRuntimes();
    }

    /**
     * Initialize runtime instances.
     *
     * @return void
     */
    private function initializeRuntimes(): void
    {
        $runtimeClass = \parallel\Runtime::class;

        for ($i = 0; $i < $this->workerCount; $i++) {
            if ($this->autoloader !== null) {
                $this->available[] = new $runtimeClass($this->autoloader);
            } else {
                $this->available[] = new $runtimeClass();
            }
        }
    }

    /**
     * Execute tasks using the runtime pool.
     *
     * @param array<callable> $tasks Array of tasks to execute
     * @return array<mixed> Results in the same order as input tasks
     * @throws \RuntimeException If pool is shutdown
     */
    public function execute(array $tasks): array
    {
        if ($this->shutdown) {
            throw new \RuntimeException('Cannot execute tasks on a shutdown pool');
        }

        if (empty($tasks)) {
            return [];
        }

        $this->lastErrors = [];

        /** @var array<int, \parallel\Future> $futures */
        $futures = [];

        /** @var array<int, \parallel\Runtime> $taskRuntimes */
        $taskRuntimes = [];

        $taskQueue = [];
        $taskIndex = 0;

        // Queue all tasks
        foreach ($tasks as $index => $task) {
            $taskQueue[$taskIndex] = [
                'index' => $index,
                'task' => $task,
            ];
            $taskIndex++;
        }

        $results = [];
        $pendingTasks = $taskQueue;
        $runningCount = 0;

        // Start initial batch up to pool size
        while ($runningCount < $this->workerCount && !empty($pendingTasks)) {
            $taskData = \array_shift($pendingTasks);
            if ($taskData === null) {
                break;
            }

            $runtime = $this->acquireRuntime();
            if ($runtime === null) {
                \array_unshift($pendingTasks, $taskData);
                break;
            }

            $task = $taskData['task'];
            $closure = $task instanceof \Closure ? $task : \Closure::fromCallable($task);

            /** @var \parallel\Future $future */
            $future = $runtime->run($closure);
            $futures[$taskData['index']] = $future;
            $taskRuntimes[$taskData['index']] = $runtime;
            $runningCount++;
        }

        $startTime = \microtime(true);
        $timeoutSeconds = Configuration::getMaxTaskTimeoutSeconds();

        while (!empty($futures)) {
            foreach ($futures as $index => $future) {
                if ($future->done()) {
                    try {
                        $results[$index] = $future->value();
                    } catch (\Throwable $e) {
                        $results[$index] = null;
                        $this->lastErrors[$index] = ['message' => $e->getMessage()];
                    }

                    $this->releaseRuntime($taskRuntimes[$index]);
                    unset($futures[$index], $taskRuntimes[$index]);
                    $runningCount--;

                    if (!empty($pendingTasks)) {
                        $taskData = \array_shift($pendingTasks);
                        if ($taskData !== null) {
                            $runtime = $this->acquireRuntime();
                            if ($runtime !== null) {
                                $task = $taskData['task'];
                                $closure = $task instanceof \Closure ? $task : \Closure::fromCallable($task);

                                /** @var \parallel\Future $newFuture */
                                $newFuture = $runtime->run($closure);
                                $futures[$taskData['index']] = $newFuture;
                                $taskRuntimes[$taskData['index']] = $runtime;
                                $runningCount++;
                            } else {
                                \array_unshift($pendingTasks, $taskData);
                            }
                        }
                    }
                }
            }

            if (!empty($futures)) {
                if (\microtime(true) - $startTime > $timeoutSeconds) {
                    foreach ($futures as $index => $future) {
                        $results[$index] = null;
                        $this->lastErrors[$index] = ['message' => 'Task timeout'];
                        if (isset($taskRuntimes[$index])) {
                            $this->releaseRuntime($taskRuntimes[$index]);
                        }
                    }
                    break;
                }

                \usleep(1000);
            }
        }

        \ksort($results);

        return $results;
    }

    /**
     * Acquire a runtime from the pool.
     *
     * @return \parallel\Runtime|null Runtime instance or null if none available
     */
    private function acquireRuntime(): ?object
    {
        if (empty($this->available)) {
            return null;
        }

        return \array_pop($this->available);
    }

    /**
     * Release a runtime back to the pool.
     *
     * @param \parallel\Runtime $runtime The runtime to release
     * @return void
     */
    private function releaseRuntime(\parallel\Runtime $runtime): void
    {
        if (!$this->shutdown && \count($this->available) < $this->workerCount) {
            $this->available[] = $runtime;
        }
    }

    /**
     * Get errors from the last execution.
     *
     * @return array<int, array{message: string}>
     */
    public function getLastErrors(): array
    {
        return $this->lastErrors;
    }

    /**
     * Check if the last execution had any errors.
     *
     * @return bool
     */
    public function hasErrors(): bool
    {
        return !empty($this->lastErrors);
    }

    /**
     * Get the number of workers in the pool.
     *
     * @return int
     */
    public function getWorkerCount(): int
    {
        return $this->workerCount;
    }

    /**
     * Check if the pool has been shut down.
     *
     * @return bool
     */
    public function isShutdown(): bool
    {
        return $this->shutdown;
    }

    /**
     * Shutdown the runtime pool gracefully.
     *
     * @return void
     */
    public function shutdown(): void
    {
        if ($this->shutdown) {
            return;
        }

        $this->shutdown = true;

        // Close all available runtimes
        foreach ($this->available as $runtime) {
            try {
                $runtime->close();
            } catch (\Throwable) {
                // Ignore close errors
            }
        }

        $this->available = [];
    }

    /**
     * Destructor - ensure runtimes are cleaned up.
     */
    public function __destruct()
    {
        $this->shutdown();
    }
}
