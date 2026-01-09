<?php

namespace Utopia\Async\Timer\Adapter;

use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use Utopia\Async\Exception\Adapter as AdapterException;
use Utopia\Async\Timer\Adapter;

/**
 * ReactPHP Timer Adapter.
 *
 * Timer implementation using ReactPHP's event loop timers.
 * Provides non-blocking delayed and periodic execution.
 *
 * Requires react/event-loop package.
 *
 * @internal Use Utopia\Async\Timer facade instead
 * @package Utopia\Async\Timer\Adapter
 */
class React extends Adapter
{
    /**
     * Whether React support has been verified
     */
    private static bool $supportVerified = false;

    /**
     * Map of our timer IDs to React timer objects
     *
     * @var array<int, TimerInterface>
     */
    private array $reactTimers = [];

    /**
     * Check if ReactPHP support is available.
     *
     * @return bool True if ReactPHP event loop is available
     */
    public static function isSupported(): bool
    {
        return \class_exists(Loop::class);
    }

    /**
     * Check if ReactPHP support is available, throwing if not.
     *
     * @return void
     * @throws AdapterException If ReactPHP is not available
     */
    protected static function checkSupport(): void
    {
        if (self::$supportVerified) {
            return;
        }

        if (!\class_exists(Loop::class)) {
            throw new AdapterException(
                'ReactPHP event loop is not available. Please install react/event-loop: composer require react/event-loop'
            );
        }

        self::$supportVerified = true;
    }

    /**
     * Schedule a callback to execute after a delay.
     *
     * @param int $milliseconds The delay before execution in milliseconds
     * @param callable $callback The callback to execute
     * @return int Timer ID
     */
    protected function doAfter(int $milliseconds, callable $callback): int
    {
        static::checkSupport();

        $timerId = $this->generateTimerId();

        $reactTimer = Loop::addTimer($milliseconds / 1000, function () use ($timerId, $callback) {
            unset($this->timers[$timerId]);
            unset($this->reactTimers[$timerId]);
            $callback();
        });

        $this->timers[$timerId] = [
            'type' => 'after',
        ];
        $this->reactTimers[$timerId] = $reactTimer;

        return $timerId;
    }

    /**
     * Schedule a callback to execute repeatedly at fixed intervals.
     *
     * @param int $milliseconds The interval between executions in milliseconds
     * @param callable $callback The callback to execute. Receives timer ID as argument.
     * @return int Timer ID
     */
    protected function doTick(int $milliseconds, callable $callback): int
    {
        static::checkSupport();

        $timerId = $this->generateTimerId();

        $reactTimer = Loop::addPeriodicTimer($milliseconds / 1000, function () use ($timerId, $callback) {
            $callback($timerId);
        });

        $this->timers[$timerId] = [
            'type' => 'tick',
        ];
        $this->reactTimers[$timerId] = $reactTimer;

        return $timerId;
    }

    /**
     * Cancel a specific timer by its ID.
     *
     * @param int $timerId The timer ID
     * @return bool True if the timer was cancelled
     */
    protected function doClear(int $timerId): bool
    {
        if (!isset($this->reactTimers[$timerId])) {
            return false;
        }

        Loop::cancelTimer($this->reactTimers[$timerId]);

        unset($this->timers[$timerId]);
        unset($this->reactTimers[$timerId]);

        return true;
    }

    /**
     * Cancel all active timers.
     *
     * @return void
     */
    protected function doClearAll(): void
    {
        foreach ($this->reactTimers as $reactTimer) {
            Loop::cancelTimer($reactTimer);
        }

        $this->timers = [];
        $this->reactTimers = [];
    }
}
