<?php

namespace Utopia\Async\Promise;

/**
 * Shared configuration for promise operations.
 *
 * All values can be configured via the Promise facade or directly via static setters.
 *
 * @package Utopia\Async\Promise
 */
final class Configuration
{
    /**
     * Default values
     */
    private const int DEFAULT_SLEEP_DURATION_US = 100; // 100μs
    private const int DEFAULT_MAX_SLEEP_DURATION_US = 10000; // 10ms
    private const float DEFAULT_COROUTINE_SLEEP_DURATION_S = 0.001; // 1ms
    private const int DEFAULT_THENABLE_TIMEOUT_SECONDS = 30; // 30 seconds

    /**
     * Configurable static properties
     */
    private static ?int $sleepDurationUs = null;
    private static ?int $maxSleepDurationUs = null;
    private static ?float $coroutineSleepDurationS = null;
    private static ?int $thenableTimeoutSeconds = null;

    /**
     * Private constructor to prevent instantiation.
     */
    private function __construct()
    {
    }

    /**
     * Get initial sleep duration in microseconds for polling.
     * Used for exponential backoff when waiting for promises.
     */
    public static function getSleepDurationUs(): int
    {
        return self::$sleepDurationUs ?? self::DEFAULT_SLEEP_DURATION_US;
    }

    /**
     * Set initial sleep duration in microseconds for polling.
     */
    public static function setSleepDurationUs(int $microseconds): void
    {
        self::$sleepDurationUs = $microseconds;
    }

    /**
     * Get maximum sleep duration in microseconds.
     * Upper bound for exponential backoff.
     */
    public static function getMaxSleepDurationUs(): int
    {
        return self::$maxSleepDurationUs ?? self::DEFAULT_MAX_SLEEP_DURATION_US;
    }

    /**
     * Set maximum sleep duration in microseconds.
     */
    public static function setMaxSleepDurationUs(int $microseconds): void
    {
        self::$maxSleepDurationUs = $microseconds;
    }

    /**
     * Get coroutine sleep duration in seconds.
     * Used for non-blocking sleep in coroutine contexts.
     */
    public static function getCoroutineSleepDurationS(): float
    {
        return self::$coroutineSleepDurationS ?? self::DEFAULT_COROUTINE_SLEEP_DURATION_S;
    }

    /**
     * Set coroutine sleep duration in seconds.
     */
    public static function setCoroutineSleepDurationS(float $seconds): void
    {
        self::$coroutineSleepDurationS = $seconds;
    }

    /**
     * Get thenable resolution timeout in seconds.
     * Maximum time to wait for external thenables to resolve.
     */
    public static function getThenableTimeoutSeconds(): int
    {
        return self::$thenableTimeoutSeconds ?? self::DEFAULT_THENABLE_TIMEOUT_SECONDS;
    }

    /**
     * Set thenable resolution timeout in seconds.
     */
    public static function setThenableTimeoutSeconds(int $seconds): void
    {
        self::$thenableTimeoutSeconds = $seconds;
    }

    /**
     * Reset all configuration to default values.
     */
    public static function reset(): void
    {
        self::$sleepDurationUs = null;
        self::$maxSleepDurationUs = null;
        self::$coroutineSleepDurationS = null;
        self::$thenableTimeoutSeconds = null;
    }
}
