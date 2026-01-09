<?php

namespace Utopia\Async\Timer\Adapter\Swoole;

use Swoole\Timer as SwooleTimer;
use Utopia\Async\Timer\Adapter;

/**
 * Swoole Timer Adapter.
 *
 * High-performance timer implementation using Swoole's built-in timer APIs.
 * Supports both one-shot (after) and recurring (tick) timers with millisecond precision.
 *
 * @internal Use Utopia\Async\Timer facade instead
 * @package Utopia\Async\Timer\Adapter\Swoole
 */
class Coroutine extends Adapter
{
    /**
     * Map of our timer IDs to Swoole timer IDs
     *
     * @var array<int, int>
     */
    private array $swooleTimers = [];

    /**
     * Check if Swoole timer support is available.
     *
     * @return bool True if Swoole extension is loaded
     */
    public static function isSupported(): bool
    {
        return \extension_loaded('swoole');
    }

    /**
     * Schedule a callback to execute after a delay.
     *
     * Uses Swoole\Timer::after() for non-blocking delayed execution.
     *
     * @param int $milliseconds The delay before execution in milliseconds
     * @param callable $callback The callback to execute
     * @return int Timer ID
     */
    protected function doAfter(int $milliseconds, callable $callback): int
    {
        $timerId = $this->generateTimerId();

        $swooleTimerId = SwooleTimer::after($milliseconds, function () use ($timerId, $callback) {
            unset($this->timers[$timerId]);
            unset($this->swooleTimers[$timerId]);
            $callback();
        });

        $this->timers[$timerId] = [
            'swoole_id' => $swooleTimerId,
            'type' => 'after',
        ];
        $this->swooleTimers[$timerId] = $swooleTimerId;

        return $timerId;
    }

    /**
     * Schedule a callback to execute repeatedly at fixed intervals.
     *
     * Uses Swoole\Timer::tick() for non-blocking periodic execution.
     *
     * @param int $milliseconds The interval between executions in milliseconds
     * @param callable $callback The callback to execute. Receives timer ID as argument.
     * @return int Timer ID
     */
    protected function doTick(int $milliseconds, callable $callback): int
    {
        $timerId = $this->generateTimerId();

        $swooleTimerId = SwooleTimer::tick($milliseconds, function () use ($timerId, $callback) {
            $callback($timerId);
        });

        $this->timers[$timerId] = [
            'swoole_id' => $swooleTimerId,
            'type' => 'tick',
        ];
        $this->swooleTimers[$timerId] = $swooleTimerId;

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
        if (!isset($this->swooleTimers[$timerId])) {
            return false;
        }

        $swooleTimerId = $this->swooleTimers[$timerId];
        $result = SwooleTimer::clear($swooleTimerId);

        unset($this->timers[$timerId]);
        unset($this->swooleTimers[$timerId]);

        return $result;
    }

    /**
     * Cancel all active timers.
     *
     * @return void
     */
    protected function doClearAll(): void
    {
        foreach ($this->swooleTimers as $swooleTimerId) {
            SwooleTimer::clear($swooleTimerId);
        }

        $this->timers = [];
        $this->swooleTimers = [];
    }
}
