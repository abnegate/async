<?php

namespace Utopia\Async\Timer\Adapter;

use Utopia\Async\Timer\Adapter;

/**
 * Synchronous Timer Adapter (fallback).
 *
 * Provides blocking timer functionality when no async runtime is available.
 * This adapter executes callbacks synchronously after sleeping for the delay.
 *
 * Note: tick() in this adapter will block indefinitely until the timer is cleared
 * from within the callback.
 *
 * @internal Use Utopia\Async\Timer facade instead
 * @package Utopia\Async\Timer\Adapter
 */
class Sync extends Adapter
{
    /**
     * Track whether tick timers should continue running
     *
     * @var array<int, bool>
     */
    private array $running = [];

    /**
     * Sync adapter is always supported as it has no dependencies.
     *
     * @return bool Always returns true
     */
    public static function isSupported(): bool
    {
        return true;
    }

    /**
     * Schedule a callback to execute after a delay (blocking).
     *
     * This method blocks the current execution while waiting for the delay.
     *
     * @param int $milliseconds The delay before execution in milliseconds
     * @param callable $callback The callback to execute
     * @return int Timer ID
     */
    protected function doAfter(int $milliseconds, callable $callback): int
    {
        $timerId = $this->generateTimerId();
        $this->timers[$timerId] = [
            'callback' => $callback,
            'interval' => $milliseconds,
            'type' => 'after',
        ];

        \usleep($milliseconds * 1000);

        if (isset($this->timers[$timerId])) {
            unset($this->timers[$timerId]);
            $callback();
        }

        return $timerId;
    }

    /**
     * Schedule a callback to execute repeatedly at fixed intervals (blocking).
     *
     * Warning: This method blocks indefinitely until the timer is cleared
     * from within the callback using Timer::clear($timerId).
     *
     * @param int $milliseconds The interval between executions in milliseconds
     * @param callable $callback The callback to execute. Receives timer ID as argument.
     * @return int Timer ID
     */
    protected function doTick(int $milliseconds, callable $callback): int
    {
        $timerId = $this->generateTimerId();
        $this->timers[$timerId] = [
            'callback' => $callback,
            'interval' => $milliseconds,
            'type' => 'tick',
        ];
        $this->running[$timerId] = true;

        while ($this->running[$timerId] ?? false) {
            \usleep($milliseconds * 1000);
            if (isset($this->timers[$timerId]) && ($this->running[$timerId] ?? false)) {
                $callback($timerId);
            } else {
                break;
            }
        }

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
        if (!isset($this->timers[$timerId])) {
            return false;
        }

        unset($this->timers[$timerId]);
        unset($this->running[$timerId]);

        return true;
    }

    /**
     * Cancel all active timers.
     *
     * @return void
     */
    protected function doClearAll(): void
    {
        $this->running = [];
        $this->timers = [];
    }
}
