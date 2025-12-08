<?php

namespace Utopia\Async\Parallel\Pool\Swoole;

use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Thread as SwooleThread;
use Swoole\Thread\Atomic;
use Swoole\Thread\Barrier;
use Swoole\Thread\Map;
use Swoole\Thread\Queue;
use Utopia\Async\GarbageCollection;
use Utopia\Async\Parallel\Constants;

/**
 * Persistent Thread Pool for efficient task execution.
 *
 * Maintains a pool of long-lived worker threads that can be reused across
 * multiple task batches, eliminating the overhead of thread creation/destruction.
 *
 * Requires Swoole >= 6.0 with thread support enabled.
 *
 * @internal Use Utopia\Async\Parallel facade instead
 * @package Utopia\Async\Parallel\Pool
 */
class Thread
{
    use GarbageCollection;

    /**
     * Array of worker threads
     *
     * @var array<int, SwooleThread>
     */
    private array $workers = [];

    /**
     * Task queue shared across all workers
     */
    private Queue $taskQueue;

    /**
     * Atomic counter for completed tasks
     */
    private Atomic $completionCounter;

    /**
     * Barrier for worker initialization synchronization
     */
    private Barrier $initBarrier;

    /**
     * Atomic flag for shutdown signaling
     */
    private Atomic $shutdownFlag;

    /**
     * Thread-safe map for results
     */
    private Map $resultMap;

    /**
     * Number of workers in the pool
     */
    private int $workerCount;

    /**
     * Whether the pool has been shut down
     */
    private bool $shutdown = false;

    /**
     * Path to the thread worker script
     */
    private string $workerScript;

    /**
     * Batch ID for isolating results between execute() calls
     */
    private string $batchId = '';

    /**
     * Create a new thread pool.
     *
     * @param int $workerCount Number of worker threads to create
     * @param string $workerScript Path to the worker script file
     * @throws \InvalidArgumentException If workerCount is invalid or script doesn't exist
     */
    public function __construct(
        int $workerCount,
        string $workerScript
    ) {
        if ($workerCount <= 0) {
            throw new \InvalidArgumentException(
                \sprintf('Worker count must be greater than 0, got %d', $workerCount)
            );
        }

        if (!\file_exists($workerScript)) {
            throw new \InvalidArgumentException(
                \sprintf('Worker script does not exist: %s', $workerScript)
            );
        }

        if (!\is_readable($workerScript)) {
            throw new \InvalidArgumentException(
                \sprintf('Worker script is not readable: %s', $workerScript)
            );
        }

        $this->workerCount = $workerCount;
        $this->workerScript = $workerScript;
        $this->taskQueue = new Queue();
        $this->completionCounter = new Atomic(0);
        $this->initBarrier = new Barrier($workerCount + 1);
        $this->shutdownFlag = new Atomic(0);
        $this->resultMap = new Map();
        $this->initializeWorkers();
    }

    /**
     * Initialize worker threads.
     *
     * @return void
     */
    private function initializeWorkers(): void
    {
        for ($i = 0; $i < $this->workerCount; $i++) {
            $this->workers[] = new SwooleThread(
                $this->workerScript,
                $this->taskQueue,
                $this->completionCounter,
                $this->initBarrier,
                $this->shutdownFlag,
                $this->resultMap
            );
        }

        // Block until all workers reach the barrier (zero-polling synchronization)
        $this->initBarrier->wait();
    }

    /**
     * Stored error information from last execution.
     *
     * @var array<int, array{message: string, exception: array<string, mixed>}>
     */
    private array $lastErrors = [];

    /**
     * Iteration counter for GC checks.
     */
    private int $gcCheckCounter = 0;

    /**
     * Execute tasks using the worker pool.
     *
     * @param array<callable> $tasks Array of tasks to execute
     * @return array<mixed> Results in the same order as input tasks
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
        $this->gcCheckCounter = 0;
        $this->completionCounter->set(0);
        $this->batchId = \uniqid('batch_', true);

        $taskIndexMap = \array_keys($tasks);
        $taskCount = \count($tasks);
        $results = \array_fill_keys($taskIndexMap, null);

        try {
            $index = 0;
            foreach ($tasks as $task) {
                $serializedTask = \Opis\Closure\serialize($task);
                // Push as ArrayList: [index, batchId, serializedClosure]
                $taskEntry = new \Swoole\Thread\ArrayList();
                $taskEntry[] = $index;
                $taskEntry[] = $this->batchId;
                $taskEntry[] = $serializedTask;
                $this->taskQueue->push($taskEntry);
                $index++;
            }

            // Wait for completion with atomic signaling
            // NOTE: This is not true futex-based blocking. The wait(0.001) call uses 1ms polling
            // internally - not zero-CPU blocking. However, it's still far more efficient than
            // busy-spinning as it yields CPU between checks.

            // Track pending task indices for O(n) collection instead of O(n²)
            $pendingIndices = \array_flip(\array_keys($taskIndexMap));

            // Use proper time-based timeout (30 seconds default)
            $startTime = \microtime(true);
            $timeoutSeconds = Constants::MAX_TASK_TIMEOUT_SECONDS;
            $deadline = $startTime + $timeoutSeconds;

            while (!empty($pendingIndices)) {
                // Collect available results (O(n) per iteration, only checks pending indices)
                $this->collectResults($taskIndexMap, $results, $pendingIndices);

                if (empty($pendingIndices)) {
                    break;
                }

                // Check if there are uncollected completions (race: result written but not yet collected)
                $currentCount = $this->completionCounter->get();
                $collectedCount = $taskCount - \count($pendingIndices);
                if ($currentCount > $collectedCount) {
                    // There are results we haven't collected yet, loop immediately
                    continue;
                }

                // No pending results - wait for worker to signal completion
                // Using 1ms polling timeout (not true futex blocking, but efficient enough)
                // The timeout also handles race where wakeup() was called before wait()
                $this->completionCounter->wait(0.001);

                // Check time-based deadline
                if (\microtime(true) >= $deadline) {
                    $elapsed = \microtime(true) - $startTime;
                    throw new \RuntimeException(
                        \sprintf(
                            'Task execution timeout after %.2f seconds. %d/%d tasks completed.',
                            $elapsed,
                            $collectedCount,
                            $taskCount
                        )
                    );
                }
            }

            return $results;
        } finally {
            // Clean up any remaining results from this batch to prevent memory leak
            $this->cleanupBatchResults($taskCount);
        }
    }

    /**
     * Clean up any remaining results from a batch to prevent memory leak.
     *
     * @param int $taskCount Number of tasks in the batch
     * @return void
     */
    private function cleanupBatchResults(int $taskCount): void
    {
        for ($i = 0; $i < $taskCount; $i++) {
            $key = "{$this->batchId}_result_{$i}";
            unset($this->resultMap[$key]);
        }
    }

    /**
     * Collect results from the result map.
     * Optimized to O(n) by only iterating pending indices instead of all tasks.
     *
     * @param array<int> $taskIndexMap Map of iteration index to original index
     * @param array<mixed> $results Results array to populate
     * @param array<int, true> $pendingIndices Set of pending iteration indices (keys are indices, values are true)
     * @return void
     */
    private function collectResults(array $taskIndexMap, array &$results, array &$pendingIndices): void
    {
        // Only iterate pending indices - O(n) instead of O(n²)
        foreach ($pendingIndices as $iterIndex => $_) {
            $key = "{$this->batchId}_result_{$iterIndex}";
            if (!isset($this->resultMap[$key])) {
                continue; // Result not ready yet
            }

            $result = $this->resultMap[$key];

            // Convert ArrayList to array if needed (Swoole Map stores arrays as ArrayList)
            if ($result instanceof \Swoole\Thread\ArrayList) {
                $result = $result->toArray();
            }

            /** @var array{error?: bool, exception?: string, message?: string, value?: string} $result */
            $originalIndex = $taskIndexMap[$iterIndex];

            if (!empty($result['error'])) {
                $results[$originalIndex] = null;
                $exception = $result['exception'] ?? '';
                if (\is_string($exception) && $exception !== '') {
                    try {
                        $exception = \unserialize($exception);
                    } catch (\Throwable $e) {
                        $exception = ['deserialization_error' => $e->getMessage()];
                    }
                }
                $this->lastErrors[$originalIndex] = [
                    'message' => $result['message'] ?? 'Unknown error',
                    'exception' => \is_array($exception) ? $exception : [],
                ];
            } else {
                $value = $result['value'] ?? null;
                // Unserialize the value (serialized to preserve array keys)
                if (\is_string($value)) {
                    try {
                        $value = \unserialize($value);
                    } catch (\Throwable $e) {
                        // If deserialization fails, store the error
                        $this->lastErrors[$originalIndex] = [
                            'message' => 'Failed to deserialize task result: ' . $e->getMessage(),
                            'exception' => [],
                        ];
                        $value = null;
                    }
                }
                $results[$originalIndex] = $value;
            }

            unset($this->resultMap[$key]);
            unset($pendingIndices[$iterIndex]);

            if (++$this->gcCheckCounter >= Constants::GC_CHECK_INTERVAL) {
                $this->gcCheckCounter = 0;
                $this->triggerGC();
            }
        }
    }

    /**
     * Get errors from the last execution.
     *
     * @return array<int, array{message: string, exception: array<string, mixed>}>
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
     * Shutdown the worker pool gracefully.
     *
     * Uses Atomic shutdown flag for faster, cleaner shutdown.
     * Waits for task queue to drain before joining workers.
     *
     * @return void
     */
    public function shutdown(): void
    {
        if ($this->shutdown) {
            return;
        }

        // Mark shutdown early to prevent new tasks
        $this->shutdown = true;

        // Wait for task queue to drain (up to 5 seconds)
        $startTime = \microtime(true);
        $drainTimeout = 5.0;
        while ($this->taskQueue->count() > 0) {
            if (\microtime(true) - $startTime > $drainTimeout) {
                // Queue didn't drain in time, force shutdown
                break;
            }
            // Use non-blocking sleep when in coroutine context
            if (SwooleCoroutine::getCid() > 0) {
                SwooleCoroutine::sleep(0.01); // 10ms
            } else {
                \usleep(10000); // 10ms between checks
            }
        }

        // Signal shutdown via Atomic flag
        $this->shutdownFlag->set(1);

        // Wait for all workers to exit cleanly (up to 1 second)
        $exitTimeout = 1.0;
        $exitStart = \microtime(true);

        while (\microtime(true) - $exitStart < $exitTimeout) {
            $allExited = true;
            foreach ($this->workers as $worker) {
                if ($worker->isAlive()) {
                    $allExited = false;
                    break;
                }
            }

            if ($allExited) {
                break;
            }

            if (SwooleCoroutine::getCid() > 0) {
                SwooleCoroutine::sleep(0.01); // 10ms
            } else {
                \usleep(10000); // 10ms
            }
        }

        foreach ($this->workers as $worker) {
            try {
                if ($worker->isAlive() && $worker->joinable()) {
                    $worker->join();
                }
            } catch (\Throwable) {
                // Ignore join errors, thread may have already exited
            }
        }

        $this->workers = [];

        // Clean up all remaining results in the result map
        $keys = $this->resultMap->keys();
        foreach ($keys as $key) {
            unset($this->resultMap[$key]);
        }
    }

    /**
     * Destructor - ensure workers are cleaned up.
     */
    public function __destruct()
    {
        $this->shutdown();
    }
}
