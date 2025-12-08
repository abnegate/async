<?php

namespace Utopia\Async;

use Utopia\Async\Parallel\Constants;

/**
 * Garbage Collection trait for managing memory in long-running operations.
 *
 * Provides efficient garbage collection that only triggers when memory usage
 * exceeds a threshold. Designed to be called periodically by the consumer.
 *
 * @package Utopia\Async\Parallel
 */
trait GarbageCollection
{
    /**
     * Trigger garbage collection if memory usage exceeds threshold.
     *
     * @return void
     */
    private function triggerGC(int $threshold = Constants::MEMORY_THRESHOLD_FOR_GC): void
    {
        $usage = \memory_get_usage(true);

        if ($usage > $threshold) {
            \gc_collect_cycles();
        }
    }
}
