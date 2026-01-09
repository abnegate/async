<?php

namespace Utopia\Async;

use Utopia\Async\Exception\Adapter as AdapterException;
use Utopia\Async\Timer\Adapter;
use Utopia\Async\Timer\Adapter\Amp as AmpAdapter;
use Utopia\Async\Timer\Adapter\React as ReactAdapter;
use Utopia\Async\Timer\Adapter\Swoole\Coroutine;
use Utopia\Async\Timer\Adapter\Sync;

/**
 * Timer facade for scheduling delayed and periodic callbacks.
 *
 * Provides a unified interface for timer operations across different async runtimes.
 * Automatically selects the appropriate adapter based on available extensions.
 *
 * @package Utopia\Async
 */
class Timer
{
    /**
     * The adapter class to use for timer operations
     *
     * @var class-string<Adapter>|null
     */
    protected static ?string $adapter = null;

    /**
     * Set the adapter class to use for all Timer operations.
     *
     * @param string $adapter Fully qualified class name of an Adapter implementation
     * @return void
     * @throws AdapterException If the adapter is not a valid timer adapter class
     */
    public static function setAdapter(string $adapter): void
    {
        if (!\is_a($adapter, Adapter::class, true)) {
            throw new AdapterException('Adapter must be a valid timer adapter class');
        }

        static::$adapter = $adapter;
    }

    /**
     * Get the current adapter class, initializing if needed.
     *
     * Auto-detects the best available adapter with the following priority:
     * 1. Swoole Coroutine (requires Swoole extension)
     * 2. ReactPHP (requires react/event-loop)
     * 3. Amp (requires amphp/amp and revolt/event-loop)
     * 4. Sync (always available, blocking fallback)
     *
     * @return class-string<Adapter>
     */
    protected static function getAdapter(): string
    {
        if (static::$adapter === null) {
            static::$adapter = static::detectAdapter();
        }

        return static::$adapter;
    }

    /**
     * Detect the best available timer adapter.
     *
     * @return class-string<Adapter>
     */
    protected static function detectAdapter(): string
    {
        if (Coroutine::isSupported()) {
            return Coroutine::class;
        }
        if (ReactAdapter::isSupported()) {
            return ReactAdapter::class;
        }
        if (AmpAdapter::isSupported()) {
            return AmpAdapter::class;
        }

        return Sync::class;
    }

    /**
     * Schedule a callback to execute after a delay.
     *
     * @param int $milliseconds The delay before execution in milliseconds
     * @param callable $callback The callback to execute
     * @return int Timer ID that can be used to cancel the timer
     */
    public static function after(int $milliseconds, callable $callback): int
    {
        return static::getAdapter()::after($milliseconds, $callback);
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
        return static::getAdapter()::tick($milliseconds, $callback);
    }

    /**
     * Cancel a specific timer by its ID.
     *
     * @param int $timerId The timer ID returned by after() or tick()
     * @return bool True if the timer was successfully cancelled
     */
    public static function clear(int $timerId): bool
    {
        return static::getAdapter()::clear($timerId);
    }

    /**
     * Cancel all active timers.
     *
     * @return void
     */
    public static function clearAll(): void
    {
        static::getAdapter()::clearAll();
    }

    /**
     * Check if a timer exists and is active.
     *
     * @param int $timerId The timer ID to check
     * @return bool True if the timer exists and is active
     */
    public static function exists(int $timerId): bool
    {
        return static::getAdapter()::exists($timerId);
    }

    /**
     * Get all active timer IDs.
     *
     * @return array<int> Array of active timer IDs
     */
    public static function getTimers(): array
    {
        return static::getAdapter()::getTimers();
    }

    /**
     * Reset the adapter (useful for testing).
     *
     * @return void
     */
    public static function reset(): void
    {
        if (static::$adapter !== null) {
            static::$adapter::resetInstance();
        }
        static::$adapter = null;
    }
}
