<?php

/**
 * Comprehensive benchmark comparing Thread, Process, and Sync adapters.
 *
 * Usage:
 *   php benchmarks/AdapterBenchmark.php [--quick] [--iterations=N]
 *
 * Options:
 *   --quick          Run a shorter benchmark suite
 *   --iterations=N   Number of iterations per test (default: 5)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Utopia\Async\Parallel\Adapter\Swoole\Process;
use Utopia\Async\Parallel\Adapter\Swoole\Thread;
use Utopia\Async\Parallel\Adapter\Sync;

class AdapterBenchmark
{
    private bool $quickMode;
    private int $iterations;
    private array $results = [];

    public function __construct(bool $quickMode = false, int $iterations = 5)
    {
        $this->quickMode = $quickMode;
        $this->iterations = $iterations;
    }

    public function run(): void
    {
        $this->printHeader();
        $this->benchmarkCpuIntensive();
        $this->benchmarkIoSimulated();
        $this->benchmarkScaling();
        $this->printSummary();

        Thread::shutdown();
        Process::shutdown();
    }

    private function benchmarkCpuIntensive(): void
    {
        $this->printSection('CPU-Intensive Workloads');

        $taskCount = $this->quickMode ? 8 : 16;
        $primeTarget = $this->quickMode ? 100000 : 200000;

        $this->runComparison(
            "Prime calculation ({$taskCount} tasks, primes up to {$primeTarget})",
            fn () => $this->createPrimeTasks($taskCount, $primeTarget)
        );

        // Matrix multiplication
        $matrixSize = $this->quickMode ? 150 : 200;
        $this->runComparison(
            "Matrix multiply ({$taskCount} tasks, {$matrixSize}x{$matrixSize})",
            fn () => $this->createMatrixTasks($taskCount, $matrixSize)
        );
    }

    private function benchmarkIoSimulated(): void
    {
        $this->printSection('I/O-Simulated Workloads (sleep-based)');

        $taskCount = $this->quickMode ? 8 : 16;
        $sleepMs = 50;

        $this->runComparison(
            "Sleep tasks ({$taskCount} tasks, {$sleepMs}ms each)",
            function () use ($taskCount, $sleepMs): array {
                $tasks = [];
                for ($i = 0; $i < $taskCount; $i++) {
                    $tasks[] = function () use ($sleepMs): bool {
                        usleep($sleepMs * 1000);
                        return true;
                    };
                }
                return $tasks;
            }
        );

        // Mixed workload
        $this->runComparison(
            "Mixed workload ({$taskCount} tasks)",
            function () use ($taskCount): array {
                $tasks = [];
                for ($i = 0; $i < $taskCount; $i++) {
                    if ($i % 2 === 0) {
                        $tasks[] = function (): int {
                            return $this->calculatePrimes(50000);
                        };
                    } else {
                        $tasks[] = function (): bool {
                            usleep(50000); // 50ms
                            return true;
                        };
                    }
                }
                return $tasks;
            }
        );
    }

    private function benchmarkScaling(): void
    {
        $this->printSection('Scaling Benchmarks');

        $taskCounts = $this->quickMode ? [4, 8, 16] : [4, 8, 16, 32];

        foreach ($taskCounts as $count) {
            $this->runComparison(
                "Scaling test ({$count} tasks)",
                fn () => $this->createPrimeTasks($count, 80000),
                false
            );
        }
    }

    private function createPrimeTasks(int $count, int $target): array
    {
        $tasks = [];
        for ($i = 0; $i < $count; $i++) {
            $tasks[] = function () use ($target): int {
                return $this->calculatePrimes($target);
            };
        }
        return $tasks;
    }

    private function createMatrixTasks(int $count, int $size): array
    {
        $tasks = [];
        for ($i = 0; $i < $count; $i++) {
            $tasks[] = function () use ($size): int {
                return $this->matrixMultiply($size);
            };
        }
        return $tasks;
    }

    /**
     * Run comparison with multiple iterations for stability.
     *
     * @param string $name Test name
     * @param callable $taskFactory Factory function that creates fresh tasks for each iteration
     * @param bool $verbose Whether to show detailed output
     */
    private function runComparison(string $name, callable $taskFactory, bool $verbose = true): void
    {
        if ($verbose) {
            echo "\n  {$name}\n";
            echo str_repeat('-', 70) . "\n";
        }

        $syncTimes = [];
        $threadTimes = [];
        $processTimes = [];
        $taskCount = 0;

        for ($iter = 0; $iter < $this->iterations; $iter++) {
            // Create fresh tasks for each iteration
            $tasks = $taskFactory();
            $taskCount = count($tasks);

            // Sync
            $start = microtime(true);
            Sync::all($tasks);
            $syncTimes[] = microtime(true) - $start;

            // Thread (shutdown and recreate to avoid conflicts)
            Thread::shutdown();
            $tasks = $taskFactory(); // Fresh tasks
            $start = microtime(true);
            Thread::all($tasks);
            $threadTimes[] = microtime(true) - $start;
            Thread::shutdown();

            // Process
            Process::shutdown();
            $tasks = $taskFactory(); // Fresh tasks
            $start = microtime(true);
            Process::all($tasks);
            $processTimes[] = microtime(true) - $start;
            Process::shutdown();
        }

        // Calculate averages
        $syncAvg = array_sum($syncTimes) / count($syncTimes);
        $threadAvg = array_sum($threadTimes) / count($threadTimes);
        $processAvg = array_sum($processTimes) / count($processTimes);

        // Calculate standard deviations
        $syncStdDev = $this->calculateStdDev($syncTimes);
        $threadStdDev = $this->calculateStdDev($threadTimes);
        $processStdDev = $this->calculateStdDev($processTimes);

        $threadSpeedup = $syncAvg / $threadAvg;
        $processSpeedup = $syncAvg / $processAvg;

        if ($verbose) {
            printf("  %-12s %7.3fs (std: %.3fs)\n", 'Sync:', $syncAvg, $syncStdDev);
            printf("  %-12s %7.3fs (std: %.3fs)  %.2fx speedup\n", 'Thread:', $threadAvg, $threadStdDev, $threadSpeedup);
            printf("  %-12s %7.3fs (std: %.3fs)  %.2fx speedup\n", 'Process:', $processAvg, $processStdDev, $processSpeedup);

            $winner = $threadAvg < $processAvg ? 'Thread' : 'Process';
            $winnerAvg = min($threadAvg, $processAvg);
            $improvement = (1 - ($winnerAvg / $syncAvg)) * 100;
            printf("  Winner: %s (%.1f%% faster than sync, %d iterations)\n", $winner, $improvement, $this->iterations);
        } else {
            printf(
                "  %3d tasks: Sync=%.3fs, Thread=%.3fs (%.2fx), Process=%.3fs (%.2fx)\n",
                $taskCount,
                $syncAvg,
                $threadAvg,
                $threadSpeedup,
                $processAvg,
                $processSpeedup
            );
        }

        $this->results[$name] = [
            'sync' => $syncAvg,
            'thread' => $threadAvg,
            'process' => $processAvg,
            'sync_stddev' => $syncStdDev,
            'thread_stddev' => $threadStdDev,
            'process_stddev' => $processStdDev,
        ];
    }

    private function calculateStdDev(array $values): float
    {
        $count = count($values);
        if ($count < 2) {
            return 0.0;
        }
        $mean = array_sum($values) / $count;
        $variance = 0.0;
        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }
        return sqrt($variance / ($count - 1));
    }

    private function calculatePrimes(int $n): int
    {
        $count = 0;
        for ($i = 2; $i <= $n; $i++) {
            $isPrime = true;
            for ($j = 2; $j * $j <= $i; $j++) {
                if ($i % $j === 0) {
                    $isPrime = false;
                    break;
                }
            }
            if ($isPrime) {
                $count++;
            }
        }
        return $count;
    }

    private function matrixMultiply(int $size): int
    {
        $a = $this->randomMatrix($size);
        $b = $this->randomMatrix($size);
        $sum = 0;

        for ($i = 0; $i < $size; $i++) {
            for ($j = 0; $j < $size; $j++) {
                $val = 0;
                for ($k = 0; $k < $size; $k++) {
                    $val += $a[$i][$k] * $b[$k][$j];
                }
                $sum += $val;
            }
        }
        return $sum;
    }

    private function randomMatrix(int $size): array
    {
        $matrix = [];
        for ($i = 0; $i < $size; $i++) {
            $matrix[$i] = [];
            for ($j = 0; $j < $size; $j++) {
                $matrix[$i][$j] = rand(1, 10);
            }
        }
        return $matrix;
    }

    private function printHeader(): void
    {
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════════════╗\n";
        echo "║           Parallel Adapter Benchmark - Thread vs Process             ║\n";
        echo "╚══════════════════════════════════════════════════════════════════════╝\n\n";

        $cpuCount = function_exists('swoole_cpu_num') ? swoole_cpu_num() : 'unknown';
        echo "System Info:\n";
        echo "  PHP Version: " . PHP_VERSION . "\n";
        echo "  Swoole Version: " . SWOOLE_VERSION . "\n";
        echo "  CPU Cores: {$cpuCount}\n";
        echo "  Mode: " . ($this->quickMode ? 'Quick' : 'Full') . "\n";
        echo "  Iterations: {$this->iterations} per test\n";
    }

    private function printSection(string $title): void
    {
        echo "\n┌" . str_repeat('─', 68) . "┐\n";
        echo "│ " . str_pad($title, 66) . " │\n";
        echo "└" . str_repeat('─', 68) . "┘\n";
    }

    private function printSummary(): void
    {
        $this->printSection('Summary');

        $threadWins = 0;
        $processWins = 0;
        $totalThreadSpeedup = 0;
        $totalProcessSpeedup = 0;
        $count = 0;

        foreach ($this->results as $times) {
            $threadSpeedup = $times['sync'] / $times['thread'];
            $processSpeedup = $times['sync'] / $times['process'];
            $totalThreadSpeedup += $threadSpeedup;
            $totalProcessSpeedup += $processSpeedup;
            $count++;

            if ($times['thread'] < $times['process']) {
                $threadWins++;
            } else {
                $processWins++;
            }
        }

        $avgThreadSpeedup = $totalThreadSpeedup / $count;
        $avgProcessSpeedup = $totalProcessSpeedup / $count;

        echo "\n";
        printf("  Thread adapter:  %d wins, %.2fx average speedup\n", $threadWins, $avgThreadSpeedup);
        printf("  Process adapter: %d wins, %.2fx average speedup\n", $processWins, $avgProcessSpeedup);
        echo "\n";

        if ($avgThreadSpeedup > $avgProcessSpeedup) {
            echo "  Recommendation: Thread adapter for best average performance.\n";
        } else {
            echo "  Recommendation: Process adapter for best average performance.\n";
        }
        echo "\n";
    }
}

// Parse command line arguments
$quickMode = in_array('--quick', $argv ?? []);
$iterations = 5;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--iterations=(\d+)$/', $arg, $matches)) {
        $iterations = (int) $matches[1];
    }
}

$benchmark = new AdapterBenchmark($quickMode, $iterations);
$benchmark->run();
