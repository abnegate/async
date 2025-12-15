<?php

/**
 * Comprehensive benchmark comparing all available parallel adapters.
 *
 * Usage:
 *   php benchmarks/AdapterBenchmark.php [--quick] [--iterations=N]
 *
 * Options:
 *   --quick          Run a shorter benchmark suite
 *   --iterations=N   Number of iterations per test (default: 5)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Utopia\Async\Parallel\Adapter\Parallel;
use Utopia\Async\Parallel\Adapter\React;
use Utopia\Async\Parallel\Adapter\Swoole\Process as SwooleProcess;
use Utopia\Async\Parallel\Adapter\Swoole\Thread as SwooleThread;
use Utopia\Async\Parallel\Adapter\Sync;

class AdapterBenchmark
{
    private bool $quickMode;
    private int $iterations;
    private array $results = [];

    /**
     * All known adapters with their display names
     *
     * @var array<string, class-string>
     */
    private array $allAdapters = [
        'Sync' => Sync::class,
        'Swoole Thread' => SwooleThread::class,
        'Swoole Process' => SwooleProcess::class,
        'Amp' => Amp::class,
        'React' => React::class,
        'ext-parallel' => Parallel::class,
    ];

    /**
     * Adapters that are supported in this environment
     *
     * @var array<string, class-string>
     */
    private array $adapters = [];

    public function __construct(bool $quickMode = false, int $iterations = 5)
    {
        $this->quickMode = $quickMode;
        $this->iterations = $iterations;

        // Detect supported adapters
        foreach ($this->allAdapters as $name => $class) {
            if ($class::isSupported()) {
                $this->adapters[$name] = $class;
            }
        }
    }

    public function run(): void
    {
        $this->printHeader();

        if (count($this->adapters) < 2) {
            echo "\nERROR: At least 2 adapters required for comparison.\n";
            echo "Please install additional dependencies.\n";
            exit(1);
        }

        $this->benchmarkCpuIntensive();
        $this->benchmarkIoSimulated();
        $this->benchmarkScaling();
        $this->printSummary();

        // Shutdown all adapters
        foreach ($this->adapters as $class) {
            $class::shutdown();
        }
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
            echo str_repeat('-', 80) . "\n";
        }

        $adapterTimes = [];
        $taskCount = 0;

        foreach ($this->adapters as $adapterName => $class) {
            $adapterTimes[$adapterName] = [];
        }

        for ($iter = 0; $iter < $this->iterations; $iter++) {
            foreach ($this->adapters as $adapterName => $class) {
                // Create fresh tasks for each iteration
                $tasks = $taskFactory();
                $taskCount = count($tasks);

                // Shutdown before to ensure clean state
                $class::shutdown();

                $start = microtime(true);
                $class::all($tasks);
                $adapterTimes[$adapterName][] = microtime(true) - $start;

                // Shutdown after
                $class::shutdown();

                // Force garbage collection to release file descriptors
                gc_collect_cycles();
            }

            // Small delay between iterations to allow OS to reclaim file descriptors
            usleep(10000); // 10ms
        }

        // Calculate averages
        $stats = [];
        $syncAvg = null;

        foreach ($this->adapters as $adapterName => $class) {
            $times = $adapterTimes[$adapterName];
            $avg = array_sum($times) / count($times);
            $stdDev = $this->calculateStdDev($times);

            $stats[$adapterName] = [
                'avg' => $avg,
                'stddev' => $stdDev,
            ];

            if ($adapterName === 'Sync') {
                $syncAvg = $avg;
            }
        }

        if ($verbose) {
            foreach ($stats as $adapterName => $stat) {
                $speedup = $syncAvg / $stat['avg'];
                if ($adapterName === 'Sync') {
                    printf("  %-15s %7.3fs (std: %.3fs)\n", $adapterName . ':', $stat['avg'], $stat['stddev']);
                } else {
                    printf("  %-15s %7.3fs (std: %.3fs)  %.2fx speedup\n", $adapterName . ':', $stat['avg'], $stat['stddev'], $speedup);
                }
            }

            // Find winner
            $bestTime = PHP_FLOAT_MAX;
            $winner = '';
            foreach ($stats as $adapterName => $stat) {
                if ($adapterName !== 'Sync' && $stat['avg'] < $bestTime) {
                    $bestTime = $stat['avg'];
                    $winner = $adapterName;
                }
            }

            if ($winner) {
                $improvement = (1 - ($bestTime / $syncAvg)) * 100;
                printf("  Winner: %s (%.1f%% faster than sync, %d iterations)\n", $winner, $improvement, $this->iterations);
            }
        } else {
            // Compact output for scaling tests
            $output = sprintf("  %3d tasks:", $taskCount);
            foreach ($stats as $adapterName => $stat) {
                $speedup = $syncAvg / $stat['avg'];
                if ($adapterName === 'Sync') {
                    $output .= sprintf(" %s=%.3fs", $adapterName, $stat['avg']);
                } else {
                    $shortName = str_replace(['Swoole ', 'ext-'], ['', ''], $adapterName);
                    $output .= sprintf(", %s=%.3fs (%.1fx)", $shortName, $stat['avg'], $speedup);
                }
            }
            echo $output . "\n";
        }

        $this->results[$name] = $stats;
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
        echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
        echo "║              Parallel Adapter Benchmark - All Adapters                       ║\n";
        echo "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";

        $cpuCount = function_exists('swoole_cpu_num') ? swoole_cpu_num() : 'unknown';
        echo "System Info:\n";
        echo "  PHP Version: " . PHP_VERSION . "\n";
        if (defined('SWOOLE_VERSION')) {
            echo "  Swoole Version: " . SWOOLE_VERSION . "\n";
        }
        echo "  CPU Cores: {$cpuCount}\n";
        echo "  Mode: " . ($this->quickMode ? 'Quick' : 'Full') . "\n";
        echo "  Iterations: {$this->iterations} per test\n";

        echo "\nDetected adapters:\n";
        foreach ($this->allAdapters as $name => $class) {
            $supported = isset($this->adapters[$name]) ? '[x]' : '[ ]';
            echo "  {$supported} {$name}\n";
        }
    }

    private function printSection(string $title): void
    {
        echo "\n┌" . str_repeat('─', 78) . "┐\n";
        echo "│ " . str_pad($title, 76) . " │\n";
        echo "└" . str_repeat('─', 78) . "┘\n";
    }

    private function printSummary(): void
    {
        $this->printSection('Summary');

        $adapterWins = [];
        $adapterSpeedups = [];

        foreach ($this->adapters as $name => $class) {
            if ($name !== 'Sync') {
                $adapterWins[$name] = 0;
                $adapterSpeedups[$name] = [];
            }
        }

        foreach ($this->results as $testName => $stats) {
            $syncAvg = $stats['Sync']['avg'] ?? 1;
            $bestTime = PHP_FLOAT_MAX;
            $winner = '';

            foreach ($stats as $adapterName => $stat) {
                if ($adapterName !== 'Sync') {
                    $speedup = $syncAvg / $stat['avg'];
                    $adapterSpeedups[$adapterName][] = $speedup;

                    if ($stat['avg'] < $bestTime) {
                        $bestTime = $stat['avg'];
                        $winner = $adapterName;
                    }
                }
            }

            if ($winner) {
                $adapterWins[$winner]++;
            }
        }

        echo "\n";
        printf("  %-15s %8s %12s\n", 'Adapter', 'Wins', 'Avg Speedup');
        echo str_repeat('-', 40) . "\n";

        foreach ($adapterWins as $name => $wins) {
            $avgSpeedup = !empty($adapterSpeedups[$name])
                ? array_sum($adapterSpeedups[$name]) / count($adapterSpeedups[$name])
                : 0;
            printf("  %-15s %8d %11.2fx\n", $name, $wins, $avgSpeedup);
        }

        echo "\n";

        // Find overall recommendation
        $maxWins = max($adapterWins);
        $bestAdapters = array_keys(array_filter($adapterWins, fn ($w) => $w === $maxWins));

        if (count($bestAdapters) === 1) {
            echo "  Recommendation: {$bestAdapters[0]} adapter for best overall performance.\n";
        } else {
            echo "  Recommendation: " . implode(' or ', $bestAdapters) . " for best overall performance.\n";
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
