<?php

namespace Utopia\Async\Parallel\Adapter;

use Opis\Closure\SerializableClosure;
use React\ChildProcess\Process;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Stream\WritableStreamInterface;
use Utopia\Async\Exception\Adapter as AdapterException;
use Utopia\Async\Parallel\Adapter;

/**
 * ReactPHP Adapter.
 *
 * Parallel execution implementation using ReactPHP's child-process component
 * for multi-process parallel processing. Uses the event loop for non-blocking
 * process management.
 *
 * Note: ReactPHP is primarily designed for async I/O, not CPU-bound parallelism.
 * This adapter spawns child processes for true parallel execution.
 *
 * Requires react/child-process and react/event-loop packages.
 *
 * @internal Use Utopia\Async\Parallel facade instead
 * @package Utopia\Async\Parallel\Adapter
 */
class React extends Adapter
{
    /**
     * Whether ReactPHP support has been verified
     */
    private static bool $supportVerified = false;

    /**
     * Path to the worker script
     */
    private static ?string $workerScript = null;

    /**
     * Run a callable in a separate process and return the result.
     *
     * @param callable $task The task to execute in parallel
     * @param mixed ...$args Arguments to pass to the task
     * @return mixed The result of the task execution
     * @throws AdapterException If ReactPHP support is not available
     * @throws \Throwable If the task throws an exception
     */
    public static function run(callable $task, mixed ...$args): mixed
    {
        static::checkSupport();

        $results = static::executeInProcesses([$task], 1, $args);
        return $results[0] ?? null;
    }

    /**
     * Execute multiple tasks in parallel using child processes.
     *
     * @param array<callable> $tasks Array of callables to execute
     * @return array<mixed> Array of results corresponding to each task
     * @throws AdapterException If ReactPHP support is not available
     */
    public static function all(array $tasks): array
    {
        static::checkSupport();

        if (empty($tasks)) {
            return [];
        }

        return static::executeInProcesses($tasks, static::getCPUCount());
    }

    /**
     * Map a function over an array in parallel using child processes.
     *
     * @param array<mixed> $items The array to map over
     * @param callable $callback Function to apply to each item: fn($item, $index) => mixed
     * @param int|null $workers Number of workers to use (null = auto-detect CPU cores)
     * @return array<mixed> Array of results in the same order as input
     * @throws AdapterException If ReactPHP support is not available
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

        $chunkResults = static::executeInProcesses($tasks, $workers ?? static::getCPUCount());

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
     * @throws AdapterException If ReactPHP support is not available
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

        static::executeInProcesses($tasks, $workers ?? static::getCPUCount());
    }

    /**
     * Execute tasks in parallel with a maximum number of concurrent processes.
     *
     * @param array<callable> $tasks Array of callables to execute
     * @param int $maxConcurrency Maximum number of processes to run simultaneously
     * @return array<mixed> Array of results corresponding to each task
     * @throws AdapterException If ReactPHP support is not available
     */
    public static function pool(array $tasks, int $maxConcurrency): array
    {
        static::checkSupport();

        if (empty($tasks)) {
            return [];
        }

        return static::executeInProcesses($tasks, $maxConcurrency);
    }

    /**
     * Create the best available event loop.
     * Prefers ev/event extensions (no FD limit), falls back to stream_select.
     *
     * @return LoopInterface
     */
    protected static function createLoop(): LoopInterface
    {
        // Try ev extension first (no FD limit)
        if (\extension_loaded('ev') && \class_exists(\React\EventLoop\ExtEvLoop::class)) {
            return new \React\EventLoop\ExtEvLoop();
        }

        // Try event extension (no FD limit)
        if (\extension_loaded('event') && \class_exists(\React\EventLoop\ExtEventLoop::class)) {
            return new \React\EventLoop\ExtEventLoop();
        }

        // Fall back to stream_select (1024 FD limit)
        return new StreamSelectLoop();
    }

    /**
     * Execute tasks in child processes using ReactPHP.
     *
     * @param array<callable> $tasks Tasks to execute
     * @param int $maxConcurrency Maximum concurrent processes
     * @param array<mixed> $defaultArgs Default arguments for single task execution
     * @return array<mixed> Results indexed by task index
     */
    protected static function executeInProcesses(array $tasks, int $maxConcurrency, array $defaultArgs = []): array
    {
        $loop = static::createLoop();

        $results = [];
        $taskQueue = [];
        $activeProcesses = 0;
        $totalTasks = \count($tasks);
        $completedTasks = 0;
        /** @var array<int|string, Process> $processes */
        $processes = [];

        foreach ($tasks as $index => $task) {
            $taskQueue[] = [
                'index' => $index,
                'task' => $task,
                'args' => $defaultArgs
            ];
        }

        /** @var array<int, \stdClass> $processState */
        $processState = [];

        $startNextTask = null;
        $startNextTask = function () use (
            &$taskQueue,
            &$results,
            &$activeProcesses,
            &$completedTasks,
            $totalTasks,
            $maxConcurrency,
            $loop,
            &$startNextTask,
            &$processState,
            &$processes
        ): void {
            while ($activeProcesses < $maxConcurrency && !empty($taskQueue)) {
                /** @var array{index: int|string, task: callable(): mixed, args: array<mixed>} $taskData */
                $taskData = \array_shift($taskQueue);
                $index = $taskData['index'];
                $task = $taskData['task'];
                $args = $taskData['args'];

                $activeProcesses++;

                $state = new \stdClass();
                $state->output = '';
                $state->errorOutput = '';
                $processState[$index] = $state;

                $serializedTask = \Opis\Closure\serialize($task);
                $serializedArgs = \serialize($args);

                $phpBinary = PHP_BINARY;
                $workerScript = static::getWorkerScript();

                /** @var Process $process */
                $processClass = Process::class;
                $process = new $processClass(
                    \escapeshellarg($phpBinary) . ' ' . \escapeshellarg($workerScript)
                );

                $process->start($loop);

                $taskPayload = \base64_encode($serializedTask) . "\n" . \base64_encode($serializedArgs) . "\n";
                $stdin = $process->stdin;
                if ($stdin instanceof WritableStreamInterface) {
                    $stdin->write($taskPayload);
                    $stdin->end();
                }

                $process->stdout?->on('data', function (string $chunk) use ($state): void {
                    $state->output .= $chunk;
                });

                $process->stderr?->on('data', function (string $chunk) use ($state): void {
                    $state->errorOutput .= $chunk;
                });

                $processes[$index] = $process;

                $process->on('exit', function ($exitCode) use (
                    $index,
                    &$results,
                    &$activeProcesses,
                    &$completedTasks,
                    $totalTasks,
                    $state,
                    $startNextTask,
                    $loop,
                    $process
                ): void {
                    $activeProcesses--;
                    $completedTasks++;

                    if ($exitCode === 0 && !empty($state->output)) {
                        $decoded = @\base64_decode(\trim($state->output), true);
                        if ($decoded !== false) {
                            $unserialized = @\unserialize($decoded);
                            if (\is_array($unserialized)) {
                                $results[$index] = $unserialized['result'] ?? null;
                            } else {
                                $results[$index] = null;
                            }
                        } else {
                            $results[$index] = null;
                        }
                    } else {
                        $results[$index] = null;
                    }

                    // Close streams to release file descriptors
                    $process->stdout?->close();
                    $process->stderr?->close();

                    $startNextTask();

                    if ($completedTasks >= $totalTasks) {
                        $loop->stop();
                    }
                });
            }
        };

        $startNextTask();

        if ($totalTasks > 0) {
            $loop->run();
        }

        // Ensure all processes are terminated and streams closed
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->terminate();
            }
            $process->stdin?->close();
            $process->stdout?->close();
            $process->stderr?->close();
        }

        // Clear references to help GC
        $processes = [];
        $processState = [];
        unset($loop, $startNextTask);

        \gc_collect_cycles();

        \ksort($results);

        return $results;
    }

    /**
     * Get or create the worker script path.
     *
     * @return string Path to the worker script
     */
    protected static function getWorkerScript(): string
    {
        // Return cached path if already set and file exists
        if (self::$workerScript !== null && \file_exists(self::$workerScript)) {
            return self::$workerScript;
        }

        self::$workerScript = __DIR__ . '/../Worker/react_worker.php';
        if (\file_exists(self::$workerScript)) {
            return self::$workerScript;
        }

        $dir = \dirname(self::$workerScript);
        if (!\is_dir($dir)) {
            \mkdir($dir, 0755, true);
        }

        $script = <<<'PHP'
<?php
// ReactPHP worker script

// Find autoloader - try multiple paths
$dir = __DIR__;
$cwd = getcwd();
$autoloadPaths = [
    // From library source (when developing the library)
    $dir . '/../../../vendor/autoload.php',
    // From vendor (when installed as dependency)
    $dir . '/../../../../autoload.php',
    $dir . '/../../../../../autoload.php',
    $dir . '/../../../../../../autoload.php',
    // From CWD
    $cwd . '/vendor/autoload.php',
    $cwd . '/../vendor/autoload.php',
];

$autoloaderFound = false;
foreach ($autoloadPaths as $path) {
    if (\file_exists($path)) {
        require_once $path;
        $autoloaderFound = true;
        break;
    }
}

if (!$autoloaderFound) {
    \fwrite(STDERR, "Autoloader not found\n");
    exit(1);
}

// Read task from stdin
$input = '';
while ($line = fgets(STDIN)) {
    $input .= $line;
}

$lines = explode("\n", trim($input));
if (count($lines) < 2) {
    exit(1);
}

$serializedTask = @base64_decode($lines[0], true);
$serializedArgs = @base64_decode($lines[1], true);

if ($serializedTask === false || $serializedArgs === false) {
    exit(1);
}

try {
    $task = \Opis\Closure\unserialize($serializedTask);
    /** @var array<mixed> $args */
    $args = @unserialize($serializedArgs);

    if (!is_callable($task)) {
        exit(1);
    }

    if (!\is_array($args)) {
        $args = [];
    }

    $result = empty($args) ? $task() : $task(...$args);

    $output = serialize(['success' => true, 'result' => $result]);
    echo base64_encode($output);
    exit(0);
} catch (Throwable $e) {
    $output = serialize(['success' => false, 'error' => $e->getMessage()]);
    echo base64_encode($output);
    exit(1);
}
PHP;

        $tempFile = self::$workerScript . '.' . \uniqid('tmp', true);
        \file_put_contents($tempFile, $script);
        \rename($tempFile, self::$workerScript);

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
     * Shutdown - no persistent resources to clean up.
     *
     * @return void
     */
    public static function shutdown(): void
    {
        // No persistent resources to clean up
    }

    /**
     * Check if ReactPHP support is available.
     *
     * @return bool True if ReactPHP support is available
     */
    public static function isSupported(): bool
    {
        return \class_exists(Loop::class)
            && \class_exists(Process::class)
            && \class_exists(SerializableClosure::class);
    }

    /**
     * Check if ReactPHP support is available.
     *
     * @return void
     * @throws AdapterException If ReactPHP support is not available
     */
    protected static function checkSupport(): void
    {
        if (self::$supportVerified) {
            return;
        }

        if (!\class_exists(Loop::class)) {
            throw new AdapterException(
                'ReactPHP event loop is not available. Please install react/event-loop: composer require react/event-loop'
            );
        }

        if (!\class_exists(Process::class)) {
            throw new AdapterException(
                'ReactPHP child-process is not available. Please install react/child-process: composer require react/child-process'
            );
        }

        if (!\class_exists(SerializableClosure::class)) {
            throw new AdapterException(
                'Opis Closure is required for task serialization. Please install opis/closure: composer require opis/closure'
            );
        }

        self::$supportVerified = true;
    }
}
