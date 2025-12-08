<?php

namespace Utopia\Async\Parallel;

/**
 * Shared configuration for parallel processing.
 *
 * All values can be configured via the Parallel facade or directly via static setters.
 *
 * @package Utopia\Async\Parallel
 */
final class Configuration
{
    /**
     * Default values
     */
    private const int DEFAULT_MAX_SERIALIZED_SIZE = 10485760; // 10MB
    private const int DEFAULT_STREAM_SELECT_TIMEOUT_US = 100000; // 100ms
    private const int DEFAULT_WORKER_SLEEP_DURATION_US = 10000; // 10ms
    private const int DEFAULT_MAX_TASK_TIMEOUT_SECONDS = 30;
    private const int DEFAULT_DEADLOCK_DETECTION_INTERVAL = 5;
    private const int DEFAULT_MEMORY_THRESHOLD_FOR_GC = 52428800; // 50MB
    private const int DEFAULT_GC_CHECK_INTERVAL = 10;

    /**
     * Configurable static properties
     */
    private static ?int $maxSerializedSize = null;
    private static ?int $streamSelectTimeoutUs = null;
    private static ?int $workerSleepDurationUs = null;
    private static ?int $maxTaskTimeoutSeconds = null;
    private static ?int $deadlockDetectionInterval = null;
    private static ?int $memoryThresholdForGc = null;
    private static ?int $gcCheckInterval = null;

    /**
     * Private constructor to prevent instantiation.
     */
    private function __construct()
    {
    }

    /**
     * Get maximum serialized data size in bytes.
     * Prevents memory exhaustion from oversized task data.
     */
    public static function getMaxSerializedSize(): int
    {
        return self::$maxSerializedSize ?? self::DEFAULT_MAX_SERIALIZED_SIZE;
    }

    /**
     * Set maximum serialized data size in bytes.
     */
    public static function setMaxSerializedSize(int $bytes): void
    {
        self::$maxSerializedSize = $bytes;
    }

    /**
     * Get stream select timeout in microseconds.
     * Used for non-blocking I/O operations.
     */
    public static function getStreamSelectTimeoutUs(): int
    {
        return self::$streamSelectTimeoutUs ?? self::DEFAULT_STREAM_SELECT_TIMEOUT_US;
    }

    /**
     * Set stream select timeout in microseconds.
     */
    public static function setStreamSelectTimeoutUs(int $microseconds): void
    {
        self::$streamSelectTimeoutUs = $microseconds;
    }

    /**
     * Get worker sleep duration in microseconds.
     * Prevents CPU spinning while waiting for tasks.
     */
    public static function getWorkerSleepDurationUs(): int
    {
        return self::$workerSleepDurationUs ?? self::DEFAULT_WORKER_SLEEP_DURATION_US;
    }

    /**
     * Set worker sleep duration in microseconds.
     */
    public static function setWorkerSleepDurationUs(int $microseconds): void
    {
        self::$workerSleepDurationUs = $microseconds;
    }

    /**
     * Get maximum task timeout in seconds.
     * Default timeout for parallel task execution.
     */
    public static function getMaxTaskTimeoutSeconds(): int
    {
        return self::$maxTaskTimeoutSeconds ?? self::DEFAULT_MAX_TASK_TIMEOUT_SECONDS;
    }

    /**
     * Set maximum task timeout in seconds.
     */
    public static function setMaxTaskTimeoutSeconds(int $seconds): void
    {
        self::$maxTaskTimeoutSeconds = $seconds;
    }

    /**
     * Get deadlock detection interval in seconds.
     * How often to check for stuck workers.
     */
    public static function getDeadlockDetectionInterval(): int
    {
        return self::$deadlockDetectionInterval ?? self::DEFAULT_DEADLOCK_DETECTION_INTERVAL;
    }

    /**
     * Set deadlock detection interval in seconds.
     */
    public static function setDeadlockDetectionInterval(int $seconds): void
    {
        self::$deadlockDetectionInterval = $seconds;
    }

    /**
     * Get memory threshold for garbage collection in bytes.
     * Triggers GC when memory usage exceeds this limit.
     */
    public static function getMemoryThresholdForGc(): int
    {
        return self::$memoryThresholdForGc ?? self::DEFAULT_MEMORY_THRESHOLD_FOR_GC;
    }

    /**
     * Set memory threshold for garbage collection in bytes.
     */
    public static function setMemoryThresholdForGc(int $bytes): void
    {
        self::$memoryThresholdForGc = $bytes;
    }

    /**
     * Get garbage collection check interval.
     * Number of completed tasks between GC checks.
     */
    public static function getGcCheckInterval(): int
    {
        return self::$gcCheckInterval ?? self::DEFAULT_GC_CHECK_INTERVAL;
    }

    /**
     * Set garbage collection check interval.
     */
    public static function setGcCheckInterval(int $taskCount): void
    {
        self::$gcCheckInterval = $taskCount;
    }

    /**
     * Reset all configuration to default values.
     */
    public static function reset(): void
    {
        self::$maxSerializedSize = null;
        self::$streamSelectTimeoutUs = null;
        self::$workerSleepDurationUs = null;
        self::$maxTaskTimeoutSeconds = null;
        self::$deadlockDetectionInterval = null;
        self::$memoryThresholdForGc = null;
        self::$gcCheckInterval = null;
    }
}
