<?php

namespace Utopia\Async\Timer;

/**
 * Abstract Timer Adapter.
 *
 * Base class for timer implementations. Provides the interface for scheduling
 * delayed and periodic callbacks. Concrete adapters must implement the core
 * timer methods using their specific runtime's timer APIs.
 *
 * Uses instance-based state to ensure thread-safety in multi-threaded contexts
 * like Swoole threads. Each thread/context gets its own adapter instance.
 * Static methods are provided for API consistency with other facades.
 *
 * @internal Use Utopia\Async\Timer facade instead
 * @package Utopia\Async\Timer
 */
abstract class Adapter
{
    /**
     * Per-class singleton instances (one per concrete adapter class)
     *
     * @var array<class-string<Adapter>, static>
     */
    private static array $instances = [];

    /**
     * Counter for generating unique timer IDs
     */
    protected int $nextTimerId = 1;

    /**
     * Map of timer IDs to their internal timer references
     *
     * @var array<int, mixed>
     */
    protected array $timers = [];

    /**
     * Get the singleton instance for this adapter.
     *
     * In Swoole threads, each thread has its own PHP context,
     * so this naturally provides per-thread instances.
     *
     * @return static
     */
    public static function getInstance(): static
    {
        $class = static::class;
        if (!isset(self::$instances[$class])) {
            self::$instances[$class] = new static();
        }
        return self::$instances[$class];
    }

    /**
     * Reset the singleton instance (useful for testing).
     *
     * @return void
     */
    public static function resetInstance(): void
    {
        $class = static::class;
        if (isset(self::$instances[$class])) {
            self::$instances[$class]->doClearAll();
            unset(self::$instances[$class]);
        }
    }

    /**
     * Check if the adapter is supported in the current environment.
     *
     * @return bool True if the adapter is supported
     */
    abstract public static function isSupported(): bool;

    /**
     * Schedule a callback to execute after a delay.
     *
     * @param int $milliseconds The delay before execution in milliseconds
     * @param callable $callback The callback to execute
     * @return int Timer ID that can be used to cancel the timer
     */
    public static function after(int $milliseconds, callable $callback): int
    {
        return static::getInstance()->doAfter($milliseconds, $callback);
    }

    /**
     * Schedule a callback to execute repeatedly at fixed intervals.
     *
     * @param int $milliseconds The interval between executions in milliseconds
     * @param callable $callback The callback to execute
     * @return int Timer ID that can be used to cancel the timer
     */
    public static function tick(int $milliseconds, callable $callback): int
    {
        return static::getInstance()->doTick($milliseconds, $callback);
    }

    /**
     * Cancel a specific timer by its ID.
     *
     * @param int $timerId The timer ID returned by after() or tick()
     * @return bool True if the timer was successfully cancelled
     */
    public static function clear(int $timerId): bool
    {
        return static::getInstance()->doClear($timerId);
    }

    /**
     * Cancel all active timers.
     *
     * @return void
     */
    public static function clearAll(): void
    {
        static::getInstance()->doClearAll();
    }

    /**
     * Check if a timer exists and is active.
     *
     * @param int $timerId The timer ID to check
     * @return bool True if the timer exists and is active
     */
    public static function exists(int $timerId): bool
    {
        return static::getInstance()->doExists($timerId);
    }

    /**
     * Get all active timer IDs.
     *
     * @return array<int> Array of active timer IDs
     */
    public static function getTimers(): array
    {
        return static::getInstance()->doGetTimers();
    }

    /**
     * Instance method: Schedule a callback to execute after a delay.
     *
     * @param int $milliseconds The delay before execution in milliseconds
     * @param callable $callback The callback to execute
     * @return int Timer ID that can be used to cancel the timer
     */
    abstract protected function doAfter(int $milliseconds, callable $callback): int;

    /**
     * Instance method: Schedule a callback to execute repeatedly at fixed intervals.
     *
     * @param int $milliseconds The interval between executions in milliseconds
     * @param callable $callback The callback to execute
     * @return int Timer ID that can be used to cancel the timer
     */
    abstract protected function doTick(int $milliseconds, callable $callback): int;

    /**
     * Instance method: Cancel a specific timer by its ID.
     *
     * @param int $timerId The timer ID returned by after() or tick()
     * @return bool True if the timer was successfully cancelled
     */
    abstract protected function doClear(int $timerId): bool;

    /**
     * Instance method: Cancel all active timers.
     *
     * @return void
     */
    protected function doClearAll(): void
    {
        foreach (\array_keys($this->timers) as $timerId) {
            $this->doClear($timerId);
        }
    }

    /**
     * Instance method: Check if a timer exists and is active.
     *
     * @param int $timerId The timer ID to check
     * @return bool True if the timer exists and is active
     */
    protected function doExists(int $timerId): bool
    {
        return isset($this->timers[$timerId]);
    }

    /**
     * Instance method: Get all active timer IDs.
     *
     * @return array<int> Array of active timer IDs
     */
    protected function doGetTimers(): array
    {
        return \array_keys($this->timers);
    }

    /**
     * Generate a new unique timer ID.
     *
     * Handles integer overflow by wrapping around and skipping active timer IDs.
     *
     * @return int The new timer ID
     */
    protected function generateTimerId(): int
    {
        $attempts = 0;
        $maxAttempts = 1000;

        do {
            $timerId = $this->nextTimerId++;

            // Handle integer overflow by wrapping to 1 (keep positive IDs)
            if ($this->nextTimerId > PHP_INT_MAX - 1) {
                $this->nextTimerId = 1;
            }

            // If this ID isn't in use, we're done
            if (!isset($this->timers[$timerId])) {
                return $timerId;
            }

            $attempts++;
        } while ($attempts < $maxAttempts);

        // This should never happen unless there are 1000+ consecutive active timers
        throw new \RuntimeException('Unable to generate unique timer ID: too many active timers');
    }
}
