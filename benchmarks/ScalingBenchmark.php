<?php

/**
 * Scaling benchmark - measures how each adapter scales with task count.
 *
 * Usage:
 *   php benchmarks/ScalingBenchmark.php [--json] [--iterations=N]
 *
 * Options:
 *   --json           Output results as JSON for charting
 *   --iterations=N   Number of iterations per test (default: 5)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Utopia\Async\Parallel\Adapter\Swoole\Process;
use Utopia\Async\Parallel\Adapter\Swoole\Thread;
use Utopia\Async\Parallel\Adapter\Sync;

// Parse command line arguments
$jsonOutput = in_array('--json', $argv ?? []);
$iterations = 5;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--iterations=(\d+)$/', $arg, $matches)) {
        $iterations = (int) $matches[1];
    }
}

$cpuCount = function_exists('swoole_cpu_num') ? swoole_cpu_num() : 4;

$taskCounts = [1, 2, 4, 8, 16, 32];

$results = [
    'meta' => [
        'cpu_cores' => $cpuCount,
        'php_version' => PHP_VERSION,
        'swoole_version' => SWOOLE_VERSION,
        'iterations' => $iterations,
        'timestamp' => date('Y-m-d H:i:s'),
    ],
    'data' => [],
];

// CPU-intensive task
$createTask = function (): callable {
    return function (): int {
        $count = 0;
        for ($num = 2; $num <= 80000; $num++) {
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

if (!$jsonOutput) {
    echo "Scaling Benchmark ({$cpuCount} CPU cores, {$iterations} iterations)\n";
    echo str_repeat('=', 85) . "\n\n";
    printf("%-6s | %-18s | %-18s | %-18s | %-10s\n", 'Tasks', 'Sync', 'Thread', 'Process', 'Best');
    echo str_repeat('-', 85) . "\n";
}

foreach ($taskCounts as $count) {
    $syncTimes = [];
    $threadTimes = [];
    $processTimes = [];

    for ($iter = 0; $iter < $iterations; $iter++) {
        // Create fresh tasks for each iteration
        $tasks = [];
        for ($i = 0; $i < $count; $i++) {
            $tasks[] = $createTask();
        }

        // Sync
        $start = microtime(true);
        Sync::all($tasks);
        $syncTimes[] = microtime(true) - $start;

        // Thread (shutdown before to ensure clean state)
        Thread::shutdown();
        $tasks = [];
        for ($i = 0; $i < $count; $i++) {
            $tasks[] = $createTask();
        }
        $start = microtime(true);
        Thread::all($tasks);
        $threadTimes[] = microtime(true) - $start;
        Thread::shutdown();

        // Process
        Process::shutdown();
        $tasks = [];
        for ($i = 0; $i < $count; $i++) {
            $tasks[] = $createTask();
        }
        $start = microtime(true);
        Process::all($tasks);
        $processTimes[] = microtime(true) - $start;
        Process::shutdown();
    }

    $syncAvg = array_sum($syncTimes) / count($syncTimes);
    $threadAvg = array_sum($threadTimes) / count($threadTimes);
    $processAvg = array_sum($processTimes) / count($processTimes);

    $syncStdDev = calculateStdDev($syncTimes);
    $threadStdDev = calculateStdDev($threadTimes);
    $processStdDev = calculateStdDev($processTimes);

    $best = $threadAvg < $processAvg ? 'Thread' : 'Process';
    $bestTime = min($threadAvg, $processAvg);
    $speedup = $syncAvg / $bestTime;

    $results['data'][] = [
        'tasks' => $count,
        'sync' => round($syncAvg, 4),
        'sync_stddev' => round($syncStdDev, 4),
        'thread' => round($threadAvg, 4),
        'thread_stddev' => round($threadStdDev, 4),
        'process' => round($processAvg, 4),
        'process_stddev' => round($processStdDev, 4),
        'thread_speedup' => round($syncAvg / $threadAvg, 2),
        'process_speedup' => round($syncAvg / $processAvg, 2),
        'best' => $best,
    ];

    if (!$jsonOutput) {
        printf(
            "%-6d | %6.3fs (+/-%.3f) | %6.3fs (+/-%.3f) | %6.3fs (+/-%.3f) | %s (%.1fx)\n",
            $count,
            $syncAvg,
            $syncStdDev,
            $threadAvg,
            $threadStdDev,
            $processAvg,
            $processStdDev,
            $best,
            $speedup
        );
    }
}

if ($jsonOutput) {
    echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
} else {
    echo str_repeat('-', 85) . "\n\n";

    echo "Analysis:\n";
    $threadWins = 0;
    $processWins = 0;
    foreach ($results['data'] as $row) {
        if ($row['best'] === 'Thread') {
            $threadWins++;
        } else {
            $processWins++;
        }
    }
    echo "  Thread wins: {$threadWins}/" . count($results['data']) . "\n";
    echo "  Process wins: {$processWins}/" . count($results['data']) . "\n\n";

    $maxSpeedup = 0;
    $optimalCount = 0;
    foreach ($results['data'] as $row) {
        $speedup = max($row['thread_speedup'], $row['process_speedup']);
        if ($speedup > $maxSpeedup) {
            $maxSpeedup = $speedup;
            $optimalCount = $row['tasks'];
        }
    }
    echo "  Best speedup: {$maxSpeedup}x at {$optimalCount} tasks\n";
    echo "  Theoretical max: {$cpuCount}x (limited by CPU cores)\n";
}

Thread::shutdown();
Process::shutdown();
