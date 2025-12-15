<?php

/**
 * Thread Worker Script
 *
 * This script runs in each worker thread of the thread pool.
 * It receives serialized tasks from the task queue, executes them,
 * and writes results directly to the shared result map.
 *
 * Optimizations:
 * - Single deserialization step (Opis only)
 * - Atomic counters for completion tracking
 * - Direct Map writes (zero-copy result storage)
 * - 10ms queue timeout (down from 100ms)
 * - Atomic shutdown flag for clean exit
 * - Barrier-based initialization (zero-polling synchronization)
 * - Atomic wait/wakeup for completion signaling (1ms polling, not true futex)
 *
 * @package Utopia\Async\Parallel\Worker
 */

$autoloaderPath = null;

$cacheFile = \sys_get_temp_dir() . '/utopia_async_autoloader.cache';
if (\file_exists($cacheFile)) {
    $cachedPath = \file_get_contents($cacheFile);
    if ($cachedPath && \file_exists($cachedPath)) {
        $autoloaderPath = $cachedPath;
    }
}

if ($autoloaderPath === null) {
    $dir = __DIR__;
    $autoloadPaths = [
        $dir . '/../../../vendor/autoload.php',
        $dir . '/../../../../vendor/autoload.php',
        $dir . '/../../../../../vendor/autoload.php',
    ];

    foreach ($autoloadPaths as $path) {
        if (\file_exists($path)) {
            $autoloaderPath = $path;
            @\file_put_contents($cacheFile, $path);
            break;
        }
    }
}

if ($autoloaderPath === null) {
    throw new \RuntimeException('Composer autoloader not found');
}

require_once $autoloaderPath;

use Utopia\Async\Exception;

/** @var array{0: \Swoole\Thread\Queue, 1: \Swoole\Thread\Atomic, 2: \Swoole\Thread\Barrier, 3: \Swoole\Thread\Atomic, 4: \Swoole\Thread\Map} $args */
$args = \Swoole\Thread::getArguments();
$taskQueue = $args[0];            // Queue of serialized closures
$completionCounter = $args[1];    // Atomic counter for completed tasks
$initBarrier = $args[2];          // Barrier for initialization synchronization
$shutdownFlag = $args[3];         // Atomic flag for shutdown
$resultMap = $args[4];            // Map for results

// Signal ready and wait for all workers + main thread
$initBarrier->wait();

while (true) {
    // Check shutdown flag first
    if ($shutdownFlag->get() === 1) {
        break;
    }

    // Reduced timeout from 100ms to 10ms
    $taskEntry = $taskQueue->pop(0.01);

    if ($taskEntry === false) {
        continue;
    }

    if ($taskEntry === null) {
        break; // Fallback for null shutdown signal
    }

    // Task entry is ArrayList: [index, batchId, serializedClosure]
    if (!($taskEntry instanceof \Swoole\Thread\ArrayList) || \count($taskEntry) < 3) {
        continue;
    }

    $index = $taskEntry[0];
    $batchId = $taskEntry[1];
    $serializedTask = $taskEntry[2];
    \assert(\is_int($index));
    \assert(\is_string($batchId));
    \assert(\is_string($serializedTask));

    try {
        $task = \Opis\Closure\unserialize($serializedTask);

        if (!\is_callable($task)) {
            throw new \RuntimeException('Task is not callable');
        }

        $result = $task();

        // Write result directly to Map with batch ID prefix
        // Serialize value to preserve array keys (ArrayList loses sparse/assoc keys)
        $resultMap["{$batchId}_result_{$index}"] = [
            'value' => \serialize($result),
            'error' => false,
        ];
    } catch (\Throwable $e) {
        $resultMap["{$batchId}_result_{$index}"] = [
            'value' => null,
            'error' => true,
            'message' => $e->getMessage(),
            'exception' => \serialize(Exception::toArray($e)),
        ];
    }

    // Increment completion counter and signal main thread
    $completionCounter->add(1);
    $completionCounter->wakeup(1);
}
