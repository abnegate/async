<?php

namespace Utopia\Async\Parallel;

/**
 * Shared constants for parallel processing configuration.
 *
 * @package Utopia\Async\Parallel
 */
final class Constants
{
    /**
     * Maximum serialized data size in bytes (10MB).
     * Prevents memory exhaustion from oversized task data.
     */
    public const int MAX_SERIALIZED_SIZE = 10485760; // 10MB

    /**
     * Stream select timeout in microseconds.
     * Used for non-blocking I/O operations.
     */
    public const int STREAM_SELECT_TIMEOUT_US = 100000; // 100ms

    /**
     * Worker sleep duration in microseconds.
     * Prevents CPU spinning while waiting for tasks.
     */
    public const int WORKER_SLEEP_DURATION_US = 10000; // 10ms

    /**
     * Maximum task timeout in seconds.
     * Default timeout for parallel task execution.
     */
    public const int MAX_TASK_TIMEOUT_SECONDS = 30;

    /**
     * Deadlock detection interval in seconds.
     * How often to check for stuck workers.
     */
    public const int DEADLOCK_DETECTION_INTERVAL = 5;

    /**
     * Memory threshold for garbage collection in bytes (50MB).
     * Triggers GC when memory usage exceeds this limit.
     */
    public const int MEMORY_THRESHOLD_FOR_GC = 52428800; // 50MB

    /**
     * Garbage collection check interval.
     * Number of completed tasks between GC checks.
     */
    public const int GC_CHECK_INTERVAL = 10;

    /**
     * Private constructor to prevent instantiation.
     * This class should only be used for its constants.
     */
    private function __construct()
    {
    }
}
