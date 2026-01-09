<?php

namespace Utopia\Async\Timer\Adapter;

use Revolt\EventLoop;
use Utopia\Async\Exception\Adapter as AdapterException;
use Utopia\Async\Timer\Adapter;

/**
 * Amp Timer Adapter.
 *
 * Timer implementation using Revolt event loop (used by Amp v3+).
 * Provides non-blocking delayed and periodic execution.
 *
 * Requires amphp/amp and revolt/event-loop packages.
 *
 * @internal Use Utopia\Async\Timer facade instead
 * @package Utopia\Async\Timer\Adapter
 */
class Amp extends Adapter
{
    /**
     * Whether Amp support has been verified
     */
    private static bool $supportVerified = false;

    /**
     * Map of our timer IDs to Revolt callback IDs
     *
     * @var array<int, string>
     */
    private array $revoltCallbacks = [];

    /**
     * Check if Amp/Revolt support is available.
     *
     * @return bool True if Revolt event loop is available
     */
    public static function isSupported(): bool
    {
        return \class_exists(EventLoop::class);
    }

    /**
     * Check if Amp/Revolt support is available, throwing if not.
     *
     * @return void
     * @throws AdapterException If Revolt is not available
     */
    protected static function checkSupport(): void
    {
        if (self::$supportVerified) {
            return;
        }

        if (!\class_exists(EventLoop::class)) {
            throw new AdapterException(
                'Revolt event loop is not available. Please install revolt/event-loop: composer require revolt/event-loop'
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

        $callbackId = EventLoop::delay($milliseconds / 1000, function () use ($timerId, $callback) {
            unset($this->timers[$timerId]);
            unset($this->revoltCallbacks[$timerId]);
            $callback();
        });

        $this->timers[$timerId] = [
            'type' => 'after',
        ];
        $this->revoltCallbacks[$timerId] = $callbackId;

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

        $callbackId = EventLoop::repeat($milliseconds / 1000, function () use ($timerId, $callback) {
            $callback($timerId);
        });

        $this->timers[$timerId] = [
            'type' => 'tick',
        ];
        $this->revoltCallbacks[$timerId] = $callbackId;

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
        if (!isset($this->revoltCallbacks[$timerId])) {
            return false;
        }

        EventLoop::cancel($this->revoltCallbacks[$timerId]);

        unset($this->timers[$timerId]);
        unset($this->revoltCallbacks[$timerId]);

        return true;
    }

    /**
     * Cancel all active timers.
     *
     * @return void
     */
    protected function doClearAll(): void
    {
        foreach ($this->revoltCallbacks as $callbackId) {
            EventLoop::cancel($callbackId);
        }

        $this->timers = [];
        $this->revoltCallbacks = [];
    }
}
