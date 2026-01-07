<?php

/**
 * Comprehensive benchmark comparing all available parallel adapters.
 *
 * Usage:
 *   php benchmarks/Benchmark.php [--json] [--iterations=N] [--load=N]
 *
 * Options:
 *   --json           Output results as JSON
 *   --iterations=N   Number of iterations per test (default: 5)
 *   --load=N         Workload intensity from 1-100 (default: 50)
 */

if (ob_get_level()) {
    ob_end_flush();
}
ob_implicit_flush(true);

require_once __DIR__ . '/../vendor/autoload.php';

use Utopia\Async\Parallel;
use Utopia\Async\Parallel\Adapter\Amp;
use Utopia\Async\Parallel\Adapter\Parallel as ParallelAdapter;
use Utopia\Async\Parallel\Adapter\React;
use Utopia\Async\Parallel\Adapter\Swoole\Process as SwooleProcess;
use Utopia\Async\Parallel\Adapter\Swoole\Thread as SwooleThread;
use Utopia\Async\Parallel\Adapter\Sync;

class Benchmark
{
    private int $iterations;
    private int $load;
    private bool $jsonOutput;
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
        'ext-parallel' => ParallelAdapter::class,
    ];

    /**
     * Adapters that are supported in this environment
     *
     * @var array<string, class-string>
     */
    private array $adapters = [];

    private int $cpuCount;

    public function __construct(int $iterations = 5, int $load = 50, bool $jsonOutput = false)
    {
        $this->iterations = $iterations;
        $this->load = max(1, min(100, $load));
        $this->jsonOutput = $jsonOutput;
        $this->cpuCount = function_exists('swoole_cpu_num') ? swoole_cpu_num() : 4;

        foreach ($this->allAdapters as $name => $class) {
            if ($class::isSupported()) {
                $this->adapters[$name] = $class;
            }
        }
    }

    /**
     * Scale a value based on load percentage.
     * Load 50 = baseline value, load 1 = 2% of baseline, load 100 = 200% of baseline.
     */
    private function scaleByLoad(int $baselineValue): int
    {
        $multiplier = $this->load / 50.0;
        return max(1, (int) round($baselineValue * $multiplier));
    }

    public function run(): void
    {
        // Increase timeout for heavy workloads (scales with load and CPU count)
        $timeout = max(60, (int) ($this->load * 2 + $this->cpuCount * 5));
        Parallel::setMaxTaskTimeoutSeconds($timeout);

        if (!$this->jsonOutput) {
            $this->printHeader();
        }

        if (count($this->adapters) < 2) {
            if ($this->jsonOutput) {
                echo json_encode(['error' => 'At least 2 adapters required for comparison'], JSON_PRETTY_PRINT) . "\n";
            } else {
                echo "\nERROR: At least 2 adapters required for comparison.\n";
                echo "Please install additional dependencies.\n";
            }
            exit(1);
        }

        $this->benchmarkCpuIntensive();
        $this->benchmarkIoSimulated();
        $this->benchmarkScaling();

        if ($this->jsonOutput) {
            $this->printJsonOutput();
        } else {
            $this->printSummary();
        }

        foreach ($this->adapters as $class) {
            $class::shutdown();
        }
    }

    private function benchmarkCpuIntensive(): void
    {
        if (!$this->jsonOutput) {
            $this->printSection('CPU-Intensive Workloads');
        }

        $taskCount = $this->cpuCount;
        $primeTarget = $this->scaleByLoad(200000);

        $this->runComparison(
            'cpu_prime',
            "Prime calculation ({$taskCount} tasks, primes up to {$primeTarget})",
            fn () => $this->createPrimeTasks($taskCount, $primeTarget)
        );

        $matrixSize = $this->scaleByLoad(200);
        $this->runComparison(
            'cpu_matrix',
            "Matrix multiply ({$taskCount} tasks, {$matrixSize}x{$matrixSize})",
            fn () => $this->createMatrixTasks($taskCount, $matrixSize)
        );
    }

    private function benchmarkIoSimulated(): void
    {
        if (!$this->jsonOutput) {
            $this->printSection('I/O-Simulated Workloads');
        }

        $taskCount = $this->cpuCount;
        $sleepMs = $this->scaleByLoad(50);

        $this->runComparison(
            'io_sleep',
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

        $mixedPrimeTarget = $this->scaleByLoad(50000);
        $mixedSleepUs = $this->scaleByLoad(50000);
        $this->runComparison(
            'io_mixed',
            "Mixed workload ({$taskCount} tasks)",
            function () use ($taskCount, $mixedPrimeTarget, $mixedSleepUs): array {
                $tasks = [];
                for ($i = 0; $i < $taskCount; $i++) {
                    if ($i % 2 === 0) {
                        $tasks[] = function () use ($mixedPrimeTarget): int {
                            $count = 0;
                            for ($num = 2; $num <= $mixedPrimeTarget; $num++) {
                                $isPrime = true;
                                for ($j = 2; $j * $j <= $num; $j++) {
                                    if ($num % $j === 0) {
                                        $isPrime = false;
                                        break;
                                    }
                                }
                                if ($isPrime) {
                                    $count++;
                                }
                            }
                            return $count;
                        };
                    } else {
                        $tasks[] = function () use ($mixedSleepUs): bool {
                            usleep($mixedSleepUs);
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
        if (!$this->jsonOutput) {
            $this->printSection('Scaling Benchmarks');
        }

        $taskCounts = [1, 2, 4, 8, 16, 32];
        $scalingPrimeTarget = $this->scaleByLoad(80000);

        foreach ($taskCounts as $count) {
            $this->runComparison(
                "scaling_{$count}",
                "Scaling test ({$count} tasks)",
                fn () => $this->createPrimeTasks($count, $scalingPrimeTarget),
                $count > 8 // verbose for smaller task counts
            );
        }
    }

    private function createPrimeTasks(int $count, int $target): array
    {
        $tasks = [];
        for ($i = 0; $i < $count; $i++) {
            $tasks[] = function () use ($target): int {
                $count = 0;
                for ($num = 2; $num <= $target; $num++) {
                    $isPrime = true;
                    for ($j = 2; $j * $j <= $num; $j++) {
                        if ($num % $j === 0) {
                            $isPrime = false;
                            break;
                        }
                    }
                    if ($isPrime) {
                        $count++;
                    }
                }
                return $count;
            };
        }
        return $tasks;
    }

    private function createMatrixTasks(int $count, int $size): array
    {
        $tasks = [];
        for ($i = 0; $i < $count; $i++) {
            $tasks[] = function () use ($size): int {
                // Create random matrices
                $a = [];
                $b = [];
                for ($i = 0; $i < $size; $i++) {
                    $a[$i] = [];
                    $b[$i] = [];
                    for ($j = 0; $j < $size; $j++) {
                        $a[$i][$j] = rand(1, 10);
                        $b[$i][$j] = rand(1, 10);
                    }
                }

                // Multiply and sum
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
            };
        }
        return $tasks;
    }

    /**
     * Run comparison with multiple iterations for stability.
     */
    private function runComparison(string $key, string $name, callable $taskFactory, bool $compact = false): void
    {
        if (!$this->jsonOutput && !$compact) {
            echo "\n  {$name}\n";
            echo str_repeat('-', 80) . "\n";
        }

        $adapterTimes = [];
        $taskCount = 0;

        foreach ($this->adapters as $adapterName => $class) {
            $adapterTimes[$adapterName] = [];
        }

        // Run all iterations for each adapter before moving to the next
        // This reuses pools within an adapter and only recreates between adapters
        foreach ($this->adapters as $adapterName => $class) {
            for ($iter = 0; $iter < $this->iterations; $iter++) {
                $tasks = $taskFactory();
                $taskCount = count($tasks);

                $start = microtime(true);
                $class::all($tasks);
                $adapterTimes[$adapterName][] = microtime(true) - $start;
            }

            // Shutdown after all iterations for this adapter
            $class::shutdown();
            gc_collect_cycles();
        }

        $stats = [];
        $syncAvg = null;

        foreach ($this->adapters as $adapterName => $class) {
            $times = $adapterTimes[$adapterName];
            $avg = array_sum($times) / count($times);
            $stdDev = $this->calculateStdDev($times);

            $stats[$adapterName] = [
                'avg' => $avg,
                'stddev' => $stdDev,
                'min' => min($times),
                'max' => max($times),
            ];

            if ($adapterName === 'Sync') {
                $syncAvg = $avg;
            }
        }

        // Calculate speedups
        foreach ($stats as $adapterName => &$stat) {
            $stat['speedup'] = $syncAvg / $stat['avg'];
        }
        unset($stat); // Break reference to avoid PHP foreach reference bug

        // Find winner
        $bestTime = PHP_FLOAT_MAX;
        $winner = '';
        foreach ($stats as $adapterName => $stat) {
            if ($adapterName !== 'Sync' && $stat['avg'] < $bestTime) {
                $bestTime = $stat['avg'];
                $winner = $adapterName;
            }
        }

        $this->results[$key] = [
            'name' => $name,
            'tasks' => $taskCount,
            'stats' => $stats,
            'winner' => $winner,
        ];

        if (!$this->jsonOutput) {
            if ($compact) {
                $output = sprintf("  %3d tasks:", $taskCount);
                foreach ($stats as $adapterName => $stat) {
                    if ($adapterName === 'Sync') {
                        $output .= sprintf(" %s=%.3fs", $adapterName, $stat['avg']);
                    } else {
                        $shortName = str_replace(['Swoole ', 'ext-'], ['', ''], $adapterName);
                        $output .= sprintf(", %s=%.3fs (%.1fx)", $shortName, $stat['avg'], $stat['speedup']);
                    }
                }
                echo $output . "\n";
            } else {
                foreach ($stats as $adapterName => $stat) {
                    if ($adapterName === 'Sync') {
                        printf(
                            "  %-15s %7.3fs (std: %.3fs, range: %.3f-%.3fs)\n",
                            $adapterName . ':',
                            $stat['avg'],
                            $stat['stddev'],
                            $stat['min'],
                            $stat['max']
                        );
                    } else {
                        printf(
                            "  %-15s %7.3fs (std: %.3fs, range: %.3f-%.3fs)  %.2fx speedup\n",
                            $adapterName . ':',
                            $stat['avg'],
                            $stat['stddev'],
                            $stat['min'],
                            $stat['max'],
                            $stat['speedup']
                        );
                    }
                }

                if ($winner) {
                    $improvement = (1 - ($bestTime / $syncAvg)) * 100;
                    printf("  Winner: %s (%.1f%% faster than sync)\n", $winner, $improvement);
                }
            }
        }
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

    private function printHeader(): void
    {
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
        echo "║                    Parallel Adapter Benchmark                                ║\n";
        echo "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";

        echo "System Info:\n";
        echo "  PHP Version: " . PHP_VERSION . "\n";
        if (defined('SWOOLE_VERSION')) {
            echo "  Swoole Version: " . SWOOLE_VERSION . "\n";
        }
        echo "  CPU Cores: {$this->cpuCount}\n";
        echo "  Iterations: {$this->iterations} per test\n";
        echo "  Load: {$this->load}% (workload intensity)\n";

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

        foreach ($this->results as $result) {
            $winner = $result['winner'];
            if ($winner && isset($adapterWins[$winner])) {
                $adapterWins[$winner]++;
            }

            foreach ($result['stats'] as $adapterName => $stat) {
                if ($adapterName !== 'Sync') {
                    $adapterSpeedups[$adapterName][] = $stat['speedup'];
                }
            }
        }

        echo "\n";
        printf("  %-15s %8s %12s %12s\n", 'Adapter', 'Wins', 'Avg Speedup', 'Max Speedup');
        echo str_repeat('-', 52) . "\n";

        foreach ($adapterWins as $name => $wins) {
            $avgSpeedup = !empty($adapterSpeedups[$name])
                ? array_sum($adapterSpeedups[$name]) / count($adapterSpeedups[$name])
                : 0;
            $maxSpeedup = !empty($adapterSpeedups[$name])
                ? max($adapterSpeedups[$name])
                : 0;
            printf("  %-15s %8d %11.2fx %11.2fx\n", $name, $wins, $avgSpeedup, $maxSpeedup);
        }

        echo "\n";

        $maxWins = max($adapterWins);
        $bestAdapters = array_keys(array_filter($adapterWins, fn ($w) => $w === $maxWins));

        if (count($bestAdapters) === 1) {
            echo "  Recommendation: {$bestAdapters[0]} for best overall performance.\n";
        } else {
            echo "  Recommendation: " . implode(' or ', $bestAdapters) . " for best overall performance.\n";
        }

        echo "  Theoretical max speedup: {$this->cpuCount}x (limited by CPU cores)\n";
        echo "\n";
    }

    private function printJsonOutput(): void
    {
        $output = [
            'meta' => [
                'cpu_cores' => $this->cpuCount,
                'php_version' => PHP_VERSION,
                'swoole_version' => defined('SWOOLE_VERSION') ? SWOOLE_VERSION : null,
                'iterations' => $this->iterations,
                'load' => $this->load,
                'timestamp' => date('Y-m-d H:i:s'),
                'adapters_available' => array_keys($this->adapters),
                'adapters_unavailable' => array_keys(array_diff_key($this->allAdapters, $this->adapters)),
            ],
            'results' => $this->results,
        ];

        echo json_encode($output, JSON_PRETTY_PRINT) . "\n";
    }
}

// Parse command line arguments
$jsonOutput = in_array('--json', $argv ?? []);
$iterations = 5;
$load = 50;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--iterations=(\d+)$/', $arg, $matches)) {
        $iterations = (int) $matches[1];
    }
    if (preg_match('/^--load=(\d+)$/', $arg, $matches)) {
        $load = (int) $matches[1];
    }
}

$benchmark = new Benchmark($iterations, $load, $jsonOutput);
$benchmark->run();
