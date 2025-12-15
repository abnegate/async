<?php

namespace Utopia\Async\Parallel\Pool\Swoole;

use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Process as SwooleProcess;
use Utopia\Async\Exception;
use Utopia\Async\Exception\Serialization as SerializationException;
use Utopia\Async\GarbageCollection;
use Utopia\Async\Parallel\Configuration;

/**
 * Persistent Process Pool for efficient task execution.
 *
 * Wraps Swoole's built-in Process\Pool to provide a simple interface for
 * executing tasks across persistent worker processes.
 *
 * @internal Use Utopia\Async\Parallel facade instead
 * @package Utopia\Async\Parallel\Pool
 */
class Process
{
    use GarbageCollection;

    /**
     * Number of workers in the pool
     */
    private int $workerCount;

    /**
     * Whether the pool has been shut down
     */
    private bool $shutdown = false;

    /**
     * Worker processes for direct communication
     *
     * @var array<int, SwooleProcess>
     */
    private array $workers = [];

    /**
     * Create a new process pool using Swoole's Process\Pool.
     *
     * @param int $workerCount Number of worker processes to create
     */
    public function __construct(int $workerCount)
    {
        $this->workerCount = $workerCount;
        $this->initializePool();
    }

    /**
     * Initialize the Swoole process pool.
     *
     * @return void
     */
    private function initializePool(): void
    {
        for ($i = 0; $i < $this->workerCount; $i++) {
            $worker = new SwooleProcess(function (SwooleProcess $worker) {
                while (true) {
                    $message = $worker->read();

                    if ($message === false || $message === '') {
                        continue;
                    }

                    if (\is_string($message) && \str_contains($message, 'STOP')) {
                        break;
                    }

                    // Use native unserialize for the wrapper array
                    $taskData = @\unserialize(\is_string($message) ? $message : '', ['allowed_classes' => true]);

                    if (!\is_array($taskData) || !isset($taskData['index'])) {
                        continue;
                    }

                    $index = $taskData['index'];

                    try {
                        // Deserialize the task using opis/closure
                        $serializedTask = $taskData['task'];
                        if (!\is_string($serializedTask)) {
                            throw new \RuntimeException('Task data is not a string');
                        }
                        $task = \Opis\Closure\unserialize($serializedTask);

                        if (!\is_callable($task)) {
                            throw new \RuntimeException('Task is not callable');
                        }

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
            }, false, SOCK_STREAM, true);

            $worker->start();

            // Set timeout for the worker process to prevent blocking forever
            // Keep blocking mode to ensure complete messages are read
            $worker->setTimeout(0.01); // 10ms timeout

            $this->workers[$i] = $worker;
        }
    }

    /**
     * Execute tasks using the worker pool.
     *
     * @param array<callable> $tasks Array of tasks to execute
     * @return array<mixed> Results in the same order as input tasks
     * @throws SerializationException If task serialization fails
     */
    public function execute(array $tasks): array
    {
        if ($this->shutdown) {
            throw new \RuntimeException('Cannot execute tasks on a shutdown pool');
        }

        if (empty($tasks)) {
            return [];
        }

        $taskList = \array_values($tasks);
        $taskIndexMap = \array_keys($tasks);
        $taskCount = \count($tasks);
        $results = [];
        $nextTaskIndex = 0; // Track next task to assign (O(1) instead of array_shift)
        $activeWorkers = [];

        // Distribute initial tasks to workers
        foreach ($this->workers as $workerId => $worker) {
            if ($nextTaskIndex < $taskCount) {
                $taskIndex = $nextTaskIndex++;
                $task = $taskList[$taskIndex];

                // Serialize closure with Opis\Closure, then wrap in native serialize
                $serializedTask = \Opis\Closure\serialize($task);
                $worker->write(\serialize([
                    'task' => $serializedTask,
                    'index' => $taskIndex,
                ]));
                $activeWorkers[$workerId] = $taskIndex;
            }
        }

        $completed = 0;
        $startTime = \time();
        $lastProgressTime = $startTime;
        $lastCompleted = 0;

        $deadlockInterval = Configuration::getDeadlockDetectionInterval();
        $maxTimeout = Configuration::getMaxTaskTimeoutSeconds();
        $workerSleepUs = Configuration::getWorkerSleepDurationUs();
        $workerSleepSeconds = $workerSleepUs / 1000000; // Pre-compute division
        $isInCoroutine = SwooleCoroutine::getCid() > 0; // Cache coroutine context check

        // Use polling approach - Swoole 6.x handles non-blocking internally
        while ($completed < $taskCount) {
            $currentTime = \time();

            // Deadlock detection: check if we've made progress
            if ($currentTime - $lastProgressTime > $deadlockInterval) {
                if ($completed === $lastCompleted) {
                    throw new \RuntimeException(
                        \sprintf(
                            'Potential deadlock detected: no progress for %d seconds. Completed %d/%d tasks.',
                            $deadlockInterval,
                            $completed,
                            $taskCount
                        )
                    );
                }
                $lastProgressTime = $currentTime;
                $lastCompleted = $completed;
            }

            // Global timeout check
            if ($currentTime - $startTime > $maxTimeout) {
                throw new \RuntimeException(
                    \sprintf(
                        'Task execution timeout: exceeded %d seconds. Completed %d/%d tasks.',
                        $maxTimeout,
                        $completed,
                        $taskCount
                    )
                );
            }

            // Poll each worker for results
            foreach ($this->workers as $workerId => $worker) {
                if (!isset($activeWorkers[$workerId])) {
                    continue;
                }

                // Use Process::read() with timeout (set during initialization)
                // Returns false if timeout expires with no data
                $response = @$worker->read();

                // False or empty response means timeout or no data
                if ($response === false || $response === '') {
                    continue;
                }

                // Use native unserialize for response (results don't contain closures)
                $result = @\unserialize(\is_string($response) ? $response : '', ['allowed_classes' => true]);

                if (!\is_array($result) || !isset($result['index']) || !\is_int($result['index'])) {
                    continue;
                }

                $originalIndex = $taskIndexMap[$result['index']];

                if (Exception::isError($result)) {
                    $results[$originalIndex] = null;
                } else {
                    $results[$originalIndex] = $result['result'] ?? null;
                }

                unset($result);
                $completed++;
                unset($activeWorkers[$workerId]);

                $this->triggerGC();

                if ($nextTaskIndex < $taskCount) {
                    $taskIndex = $nextTaskIndex++;
                    $task = $taskList[$taskIndex];

                    // Serialize closure with Opis\Closure, then wrap in native serialize
                    $serializedTask = \Opis\Closure\serialize($task);
                    $worker->write(\serialize([
                        'task' => $serializedTask,
                        'index' => $taskIndex,
                    ]));
                    $activeWorkers[$workerId] = $taskIndex;
                    unset($task, $serializedTask);
                }
            }

            if (!empty($activeWorkers)) {
                // Use non-blocking sleep when in coroutine context (cached check)
                if ($isInCoroutine) {
                    SwooleCoroutine::sleep($workerSleepSeconds);
                } else {
                    \usleep($workerSleepUs);
                }
            }
        }

        // Clear task references before returning
        unset($taskList, $taskIndexMap, $activeWorkers);

        return $results;
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
     * Check if all workers in the pool are healthy (still running).
     *
     * @return bool True if all workers are alive, false otherwise
     */
    public function isHealthy(): bool
    {
        if ($this->shutdown || empty($this->workers)) {
            return false;
        }

        foreach ($this->workers as $worker) {
            // Use signal 0 to check if process exists without actually sending a signal
            if (!SwooleProcess::kill($worker->pid, 0)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Shutdown the worker pool gracefully.
     *
     * @return void
     */
    public function shutdown(): void
    {
        if ($this->shutdown) {
            return;
        }

        foreach ($this->workers as $worker) {
            $worker->write('STOP');
        }

        // Wait for each worker process to exit
        foreach ($this->workers as $_) {
            SwooleProcess::wait(true);
        }

        $this->workers = [];
        $this->shutdown = true;
    }

    /**
     * Destructor - ensure workers are cleaned up.
     */
    public function __destruct()
    {
        $this->shutdown();
    }
}
