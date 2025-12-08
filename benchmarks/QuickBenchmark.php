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

require_once __DIR__ . '/../vendor/autoload.php';

use Utopia\Async\Parallel\Adapter\Swoole\Process;
use Utopia\Async\Parallel\Adapter\Swoole\Thread;
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
echo str_repeat('=', 60) . "\n\n";

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

$syncTimes = [];
$threadTimes = [];
$processTimes = [];
$primeCount = 0;

for ($iter = 1; $iter <= $iterations; $iter++) {
    echo "  Iteration {$iter}/{$iterations}...\r";

    // Rebuild tasks for each iteration
    $tasks = [];
    for ($i = 0; $i < $taskCount; $i++) {
        $tasks[] = $createTask();
    }

    // Sync
    $start = microtime(true);
    $syncResults = Sync::all($tasks);
    $syncTimes[] = microtime(true) - $start;
    $primeCount = $syncResults[0] ?? 0;

    // Thread (shutdown before to ensure clean state)
    Thread::shutdown();
    $start = microtime(true);
    Thread::all($tasks);
    $threadTimes[] = microtime(true) - $start;
    Thread::shutdown();

    // Process
    Process::shutdown();
    $start = microtime(true);
    Process::all($tasks);
    $processTimes[] = microtime(true) - $start;
    Process::shutdown();
}

echo str_repeat(' ', 30) . "\r"; // Clear progress line

// Calculate averages
$syncAvg = array_sum($syncTimes) / count($syncTimes);
$threadAvg = array_sum($threadTimes) / count($threadTimes);
$processAvg = array_sum($processTimes) / count($processTimes);

// Calculate standard deviations
$syncStdDev = calculateStdDev($syncTimes);
$threadStdDev = calculateStdDev($threadTimes);
$processStdDev = calculateStdDev($processTimes);

$threadSpeedup = $syncAvg / $threadAvg;
$processSpeedup = $syncAvg / $processAvg;

printf("%-10s %8s %8s   %s\n", 'Adapter', 'Avg', 'StdDev', 'Speedup');
echo str_repeat('-', 50) . "\n";
printf(
    "%-10s %7.3fs %7.3fs   (baseline) - %d primes found\n",
    'Sync:',
    $syncAvg,
    $syncStdDev,
    $primeCount
);
printf(
    "%-10s %7.3fs %7.3fs   %.2fx speedup\n",
    'Thread:',
    $threadAvg,
    $threadStdDev,
    $threadSpeedup
);
printf(
    "%-10s %7.3fs %7.3fs   %.2fx speedup\n",
    'Process:',
    $processAvg,
    $processStdDev,
    $processSpeedup
);

echo "\n";
$winner = $threadAvg < $processAvg ? 'Thread' : 'Process';
$improvement = max($threadSpeedup, $processSpeedup);
printf("Winner: %s with %.2fx average speedup over sequential execution\n", $winner, $improvement);

// Show min/max for context
echo "\nDetailed timing (min/max):\n";
printf("  Sync:    %.3fs - %.3fs\n", min($syncTimes), max($syncTimes));
printf("  Thread:  %.3fs - %.3fs\n", min($threadTimes), max($threadTimes));
printf("  Process: %.3fs - %.3fs\n", min($processTimes), max($processTimes));

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
