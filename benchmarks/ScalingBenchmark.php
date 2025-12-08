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

use Utopia\Async\Parallel\Adapter\Swoole\Process as SwooleProcess;
use Utopia\Async\Parallel\Adapter\Swoole\Thread as SwooleThread;
use Utopia\Async\Parallel\Adapter\Amp;
use Utopia\Async\Parallel\Adapter\React;
use Utopia\Async\Parallel\Adapter\Parallel;
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

$taskCounts = [1, 2, 4, 8, 16, 32];

$results = [
    'meta' => [
        'cpu_cores' => $cpuCount,
        'php_version' => PHP_VERSION,
        'swoole_version' => defined('SWOOLE_VERSION') ? SWOOLE_VERSION : null,
        'iterations' => $iterations,
        'timestamp' => date('Y-m-d H:i:s'),
        'adapters_available' => array_keys($adapters),
        'adapters_unavailable' => array_keys(array_diff_key($allAdapters, $adapters)),
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
    echo str_repeat('=', 100) . "\n\n";

    echo "Detected adapters:\n";
    foreach ($allAdapters as $name => $class) {
        $supported = isset($adapters[$name]) ? '[x]' : '[ ]';
        echo "  {$supported} {$name}\n";
    }
    echo "\n";

    if (count($adapters) < 2) {
        echo "ERROR: At least 2 adapters required for comparison.\n";
        exit(1);
    }

    // Print header
    $header = sprintf("%-6s | %-18s", 'Tasks', 'Sync');
    foreach ($adapters as $name => $class) {
        if ($name !== 'Sync') {
            $shortName = str_replace(['Swoole ', 'ext-'], ['', ''], $name);
            $header .= sprintf(" | %-18s", $shortName);
        }
    }
    $header .= " | Best";
    echo $header . "\n";
    echo str_repeat('-', strlen($header) + 10) . "\n";
}

foreach ($taskCounts as $count) {
    $adapterTimes = [];

    foreach ($adapters as $name => $class) {
        $adapterTimes[$name] = [];
    }

    for ($iter = 0; $iter < $iterations; $iter++) {
        foreach ($adapters as $name => $class) {
            // Create fresh tasks for each iteration
            $tasks = [];
            for ($i = 0; $i < $count; $i++) {
                $tasks[] = $createTask();
            }

            // Shutdown before to ensure clean state
            $class::shutdown();

            $start = microtime(true);
            $class::all($tasks);
            $adapterTimes[$name][] = microtime(true) - $start;

            // Shutdown after
            $class::shutdown();

            // Force garbage collection to release file descriptors
            gc_collect_cycles();
        }

        // Small delay between iterations to allow OS to reclaim file descriptors
        usleep(10000); // 10ms
    }

    // Calculate stats for each adapter
    $stats = [];
    $syncAvg = null;

    foreach ($adapters as $name => $class) {
        $times = $adapterTimes[$name];
        $avg = array_sum($times) / count($times);
        $stdDev = calculateStdDev($times);

        $stats[$name] = [
            'avg' => $avg,
            'stddev' => $stdDev,
        ];

        if ($name === 'Sync') {
            $syncAvg = $avg;
        }
    }

    // Find best adapter
    $bestTime = PHP_FLOAT_MAX;
    $best = 'Sync';
    foreach ($stats as $name => $stat) {
        if ($name !== 'Sync' && $stat['avg'] < $bestTime) {
            $bestTime = $stat['avg'];
            $best = $name;
        }
    }
    $speedup = $syncAvg / $bestTime;

    // Store results for JSON output
    $dataRow = [
        'tasks' => $count,
        'best' => $best,
    ];

    foreach ($adapters as $name => $class) {
        $key = strtolower(str_replace([' ', '-'], '_', $name));
        $dataRow[$key] = round($stats[$name]['avg'], 4);
        $dataRow[$key . '_stddev'] = round($stats[$name]['stddev'], 4);
        if ($name !== 'Sync') {
            $dataRow[$key . '_speedup'] = round($syncAvg / $stats[$name]['avg'], 2);
        }
    }

    $results['data'][] = $dataRow;

    if (!$jsonOutput) {
        $output = sprintf(
            "%-6d | %6.3fs (+/-%.3f)",
            $count,
            $stats['Sync']['avg'],
            $stats['Sync']['stddev']
        );

        foreach ($adapters as $name => $class) {
            if ($name !== 'Sync') {
                $adapterSpeedup = $syncAvg / $stats[$name]['avg'];
                $output .= sprintf(
                    " | %6.3fs (+/-%.3f)",
                    $stats[$name]['avg'],
                    $stats[$name]['stddev']
                );
            }
        }

        $shortBest = str_replace(['Swoole ', 'ext-'], ['', ''], $best);
        $output .= sprintf(" | %s (%.1fx)", $shortBest, $speedup);

        echo $output . "\n";
    }
}

if ($jsonOutput) {
    echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
} else {
    echo str_repeat('-', 100) . "\n\n";

    echo "Analysis:\n";

    // Count wins per adapter
    $wins = [];
    foreach ($adapters as $name => $class) {
        if ($name !== 'Sync') {
            $wins[$name] = 0;
        }
    }

    foreach ($results['data'] as $row) {
        if (isset($wins[$row['best']])) {
            $wins[$row['best']]++;
        }
    }

    foreach ($wins as $name => $count) {
        $shortName = str_replace(['Swoole ', 'ext-'], ['', ''], $name);
        echo "  {$shortName} wins: {$count}/" . count($results['data']) . "\n";
    }
    echo "\n";

    // Find best speedup
    $maxSpeedup = 0;
    $optimalCount = 0;
    $optimalAdapter = '';

    foreach ($results['data'] as $row) {
        foreach ($adapters as $name => $class) {
            if ($name !== 'Sync') {
                $key = strtolower(str_replace([' ', '-'], '_', $name)) . '_speedup';
                if (isset($row[$key]) && $row[$key] > $maxSpeedup) {
                    $maxSpeedup = $row[$key];
                    $optimalCount = $row['tasks'];
                    $optimalAdapter = $name;
                }
            }
        }
    }

    $shortOptimal = str_replace(['Swoole ', 'ext-'], ['', ''], $optimalAdapter);
    echo "  Best speedup: {$maxSpeedup}x at {$optimalCount} tasks ({$shortOptimal})\n";
    echo "  Theoretical max: {$cpuCount}x (limited by CPU cores)\n";
}

// Shutdown all adapters
foreach ($adapters as $class) {
    $class::shutdown();
}
