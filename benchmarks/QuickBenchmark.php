<?php

/**
 * Quick benchmark for rapid adapter comparison.
 *
 * Usage:
 *   php benchmarks/QuickBenchmark.php [--iterations=N]
 *
 * Options:
 *   --iterations=N   Number of iterations per test (default: 5)
 */

if (ob_get_level()) {
    ob_end_flush();
}
ob_implicit_flush(true);

require_once __DIR__ . '/../vendor/autoload.php';

use Utopia\Async\Parallel\Adapter\Amp;
use Utopia\Async\Parallel\Adapter\Parallel;
use Utopia\Async\Parallel\Adapter\React;
use Utopia\Async\Parallel\Adapter\Swoole\Process as SwooleProcess;
use Utopia\Async\Parallel\Adapter\Swoole\Thread as SwooleThread;
use Utopia\Async\Parallel\Adapter\Sync;

// Parse iterations from command line
$iterations = 5;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--iterations=(\d+)$/', $arg, $matches)) {
        $iterations = (int) $matches[1];
    }
}

$cpuCount = function_exists('swoole_cpu_num') ? swoole_cpu_num() : 4;

echo "Quick Adapter Benchmark ({$cpuCount} CPU cores, {$iterations} iterations)\n";
echo str_repeat('=', 70) . "\n\n";

// Define all adapters with their names and classes
$allAdapters = [
    'Sync' => Sync::class,
    'Swoole Thread' => SwooleThread::class,
    'Swoole Process' => SwooleProcess::class,
    'Amp' => Amp::class,
    'React' => React::class,
    'ext-parallel' => Parallel::class,
];

// Filter to only supported adapters
$adapters = [];
foreach ($allAdapters as $name => $class) {
    if ($class::isSupported()) {
        $adapters[$name] = $class;
    }
}

echo "Detected adapters:\n";
foreach ($allAdapters as $name => $class) {
    $supported = $class::isSupported() ? '[x]' : '[ ]';
    echo "  {$supported} {$name}\n";
}
echo "\n";

if (count($adapters) < 2) {
    echo "ERROR: At least 2 adapters required for comparison. Please install additional dependencies.\n";
    exit(1);
}

// CPU-intensive task: calculate primes up to N
$primeTarget = 200000;

$createTask = function () use ($primeTarget): callable {
    return function () use ($primeTarget): int {
        $count = 0;
        for ($num = 2; $num <= $primeTarget; $num++) {
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
};

$taskCount = $cpuCount;

echo "Running {$taskCount} CPU-intensive tasks (primes up to {$primeTarget})...\n";
echo "Each adapter tested {$iterations} times, showing average results.\n\n";

$results = [];
$primeCount = 0;

// Detect if running in TTY for progress display
$isTty = function_exists('posix_isatty') && posix_isatty(STDOUT);

for ($iter = 1; $iter <= $iterations; $iter++) {
    if ($isTty) {
        echo "  Iteration {$iter}/{$iterations}...\r";
    } elseif ($iter === 1 || $iter === $iterations || $iter % 5 === 0) {
        echo "  Iteration {$iter}/{$iterations}...\n";
    }

    foreach ($adapters as $name => $class) {
        // Rebuild tasks for each iteration
        $tasks = [];
        for ($i = 0; $i < $taskCount; $i++) {
            $tasks[] = $createTask();
        }

        // Shutdown before to ensure clean state
        $class::shutdown();

        $start = microtime(true);
        $taskResults = $class::all($tasks);
        $results[$name][] = microtime(true) - $start;

        if ($name === 'Sync' && isset($taskResults[0])) {
            $primeCount = $taskResults[0];
        }

        // Shutdown after
        $class::shutdown();

        // Force garbage collection to release file descriptors
        gc_collect_cycles();
    }

    // Small delay between iterations to allow OS to reclaim file descriptors
    usleep(10000); // 10ms
}

if ($isTty) {
    echo str_repeat(' ', 30) . "\r"; // Clear progress line
}
echo "\n";

// Calculate averages and standard deviations
$stats = [];
$syncAvg = null;

foreach ($adapters as $name => $class) {
    $times = $results[$name];
    $avg = array_sum($times) / count($times);
    $stdDev = calculateStdDev($times);

    $stats[$name] = [
        'avg' => $avg,
        'stddev' => $stdDev,
        'min' => min($times),
        'max' => max($times),
    ];

    if ($name === 'Sync') {
        $syncAvg = $avg;
    }
}

// Print results
printf("%-15s %8s %8s   %s\n", 'Adapter', 'Avg', 'StdDev', 'Speedup');
echo str_repeat('-', 60) . "\n";

$bestTime = PHP_FLOAT_MAX;
$bestAdapter = '';

foreach ($stats as $name => $stat) {
    $speedup = $syncAvg / $stat['avg'];

    if ($name === 'Sync') {
        printf(
            "%-15s %7.3fs %7.3fs   (baseline) - %d primes found\n",
            $name . ':',
            $stat['avg'],
            $stat['stddev'],
            $primeCount
        );
    } else {
        printf(
            "%-15s %7.3fs %7.3fs   %.2fx speedup\n",
            $name . ':',
            $stat['avg'],
            $stat['stddev'],
            $speedup
        );

        if ($stat['avg'] < $bestTime) {
            $bestTime = $stat['avg'];
            $bestAdapter = $name;
        }
    }
}

echo "\n";
$improvement = $syncAvg / $bestTime;
printf("Winner: %s with %.2fx average speedup over sequential execution\n", $bestAdapter, $improvement);

// Show min/max for context
echo "\nDetailed timing (min/max):\n";
foreach ($stats as $name => $stat) {
    printf("  %-15s %.3fs - %.3fs\n", $name . ':', $stat['min'], $stat['max']);
}

/**
 * Calculate standard deviation of an array of values.
 */
function calculateStdDev(array $values): float
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
