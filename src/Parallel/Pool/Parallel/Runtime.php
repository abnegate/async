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
     * @var array<int|string, array{message: string, exception?: \Throwable}>
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
     * Tasks can be simple callables or arrays with 'task' and 'args':
     * - Simple: $tasks = [fn() => 1, fn() => 2]
     * - With args: $tasks = [['task' => $fn, 'args' => [1, 2]], ...]
     *
     * @param array<callable|array{task: callable, args: array<mixed>}> $tasks Array of tasks to execute
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

        /** @var array<int|string, \parallel\Future> $futures */
        $futures = [];

        /** @var array<int|string, \parallel\Runtime> $taskRuntimes */
        $taskRuntimes = [];

        $taskQueue = [];
        $taskIndex = 0;

        foreach ($tasks as $index => $task) {
            if (\is_array($task) && isset($task['task'])) {
                $taskQueue[$taskIndex] = [
                    'index' => $index,
                    'task' => $task['task'],
                    'args' => $task['args'] ?? [],
                ];
            } else {
                $taskQueue[$taskIndex] = [
                    'index' => $index,
                    'task' => $task,
                    'args' => [],
                ];
            }
            $taskIndex++;
        }

        $results = [];
        $pendingTaskIndex = 0; // Track current position in queue instead of shifting
        $runningCount = 0;

        $closures = [];
        foreach ($taskQueue as $index => $taskData) {
            $task = $taskData['task'];
            /** @var callable $task */
            $closures[$index] = $task instanceof \Closure ? $task : \Closure::fromCallable($task);
        }

        // Start initial batch up to pool size
        while ($runningCount < $this->workerCount && $pendingTaskIndex < \count($taskQueue)) {
            /** @var array{index: int|string, task: callable, args: array<mixed>} $taskData */
            $taskData = $taskQueue[$pendingTaskIndex];

            $runtime = $this->acquireRuntime();
            if ($runtime === null) {
                break;
            }

            $closure = $closures[$pendingTaskIndex];
            $pendingTaskIndex++;

            /** @var \parallel\Future $future */
            if (!empty($taskData['args'])) {
                $future = $runtime->run($closure, $taskData['args']);
            } else {
                $future = $runtime->run($closure);
            }
            $futures[$taskData['index']] = $future;
            $taskRuntimes[$taskData['index']] = $runtime;
            $runningCount++;
        }

        $startTime = \microtime(true);
        $timeoutSeconds = Configuration::getMaxTaskTimeoutSeconds();

        while (!empty($futures)) {
            foreach ($futures as $index => $future) {
                /** @var \parallel\Future $future */
                if ($future->done()) {
                    try {
                        $results[$index] = $future->value();
                    } catch (\Throwable $e) {
                        $results[$index] = null;
                        $this->lastErrors[$index] = [
                            'message' => $e->getMessage(),
                            'exception' => $e,
                        ];
                    }

                    $this->releaseRuntime($taskRuntimes[$index]);
                    unset($futures[$index], $taskRuntimes[$index]);
                    $runningCount--;

                    if ($pendingTaskIndex < \count($taskQueue)) {
                        /** @var array{index: int|string, task: callable, args: array<mixed>} $taskData */
                        $taskData = $taskQueue[$pendingTaskIndex];
                        $runtime = $this->acquireRuntime();
                        if ($runtime !== null) {
                            $closure = $closures[$pendingTaskIndex];
                            $pendingTaskIndex++;

                            /** @var \parallel\Future $newFuture */
                            if (!empty($taskData['args'])) {
                                $newFuture = $runtime->run($closure, $taskData['args']);
                            } else {
                                $newFuture = $runtime->run($closure);
                            }
                            $futures[$taskData['index']] = $newFuture;
                            $taskRuntimes[$taskData['index']] = $runtime;
                            $runningCount++;
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
                            try {
                                $taskRuntimes[$index]->kill();
                            } catch (\Throwable) {
                                // Ignore kill errors
                            }
                        }
                    }
                    break;
                }

                \usleep(Configuration::getWorkerSleepDurationUs());
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
    private function acquireRuntime(): ?\parallel\Runtime
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
     * @return array<int|string, array{message: string, exception?: \Throwable}>
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
