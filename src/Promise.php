<?php

namespace Utopia\Async;

use Utopia\Async\Exception\Adapter as AdapterException;
use Utopia\Async\Promise\Adapter;
use Utopia\Async\Promise\Adapter\Amp as AmpAdapter;
use Utopia\Async\Promise\Adapter\React as ReactAdapter;
use Utopia\Async\Promise\Adapter\Swoole\Coroutine;
use Utopia\Async\Promise\Adapter\Sync;

/**
 * Promise facade for asynchronous operations.
 *
 * Provides a unified interface for promise-based async operations (or a synchronous fallback).
 * Automatically selects the appropriate adapter based on available extensions.
 *
 * @package Utopia\Async
 */
class Promise
{
    /**
     * The adapter class to use for promise operations
     *
     * @var class-string<Adapter>
     */
    protected static string $adapter;

    /**
     * Set the adapter class to use for all Promise operations.
     *
     * @param string $adapter Fully qualified class name of an Adapter implementation
     * @return void
     * @throws AdapterException If the adapter is not a valid promise adapter class
     */
    public static function setAdapter(string $adapter): void
    {
        if (!\is_a($adapter, Adapter::class, true)) {
            throw new AdapterException('Adapter must be a valid promise adapter class');
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
     * 4. Sync (always available, synchronous fallback)
     *
     * @return class-string<Adapter>
     */
    protected static function getAdapter(): string
    {
        if (!isset(static::$adapter)) {
            static::$adapter = static::detectAdapter();
        }

        return static::$adapter;
    }

    /**
     * Detect the best available promise adapter.
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
     * Run a callable asynchronously and return a promise.
     *
     * The callable's return value becomes the resolved value. Exceptions are automatically
     * caught and converted to rejections.
     *
     * @param callable $callable The function to execute asynchronously
     * @return Adapter A promise that resolves with the callable's return value
     */
    public static function async(callable $callable): Adapter
    {
        return static::getAdapter()::async($callable);
    }

    /**
     * Run a callable asynchronously and immediately await the result.
     *
     * Shorthand for async()->await()
     *
     * @param callable $callable The function to execute asynchronously
     * @return mixed The result of the callable
     * @throws \Throwable If the callable throws or the promise is rejected
     */
    public static function run(callable $callable): mixed
    {
        return static::getAdapter()::run($callable);
    }

    /**
     * Create a promise that resolves after a specified delay.
     *
     * @param int $milliseconds The delay in milliseconds
     * @return Adapter A promise that resolves to null after the delay
     */
    public static function delay(int $milliseconds): Adapter
    {
        return static::getAdapter()::delay($milliseconds);
    }

    /**
     * Wait for all promises to complete.
     *
     * Returns a promise that resolves when all input promises have resolved, or rejects
     * when any input promise rejects.
     *
     * @param array<Adapter> $promises Array of Promise instances
     * @return Adapter A promise that resolves to an array of results
     */
    public static function all(array $promises): Adapter
    {
        return static::getAdapter()::all($promises);
    }

    /**
     * Execute multiple callables concurrently and wait for all to complete.
     *
     * Converts each callable to a promise via async() and waits for all to resolve.
     * This is a convenience method equivalent to Promise::all(array_map(Promise::async(...), $callables)).
     *
     * @param array<callable> $callables Array of callables to execute concurrently
     * @return Adapter A promise that resolves to an array of results
     */
    public static function map(array $callables): Adapter
    {
        return static::all(\array_map(static::async(...), $callables));
    }

    /**
     * Race multiple promises.
     *
     * Returns a promise that resolves or rejects as soon as one of the input promises settles.
     *
     * @param array<Adapter> $promises Array of Promise instances
     * @return Adapter A promise that settles with the first settled promise's result
     */
    public static function race(array $promises): Adapter
    {
        return static::getAdapter()::race($promises);
    }

    /**
     * Wait for all promises to settle.
     *
     * Returns a promise that resolves when all input promises have settled (either
     * fulfilled or rejected), with an array describing each promise's outcome.
     *
     * @param array<Adapter> $promises Array of Promise instances
     * @return Adapter A promise that resolves to an array of settlement descriptors
     */
    public static function allSettled(array $promises): Adapter
    {
        return static::getAdapter()::allSettled($promises);
    }

    /**
     * Wait for the first fulfilled promise.
     *
     * Returns a promise that resolves when any of the input promises fulfills,
     * or rejects if all input promises reject.
     *
     * @param array<Adapter> $promises Array of Promise instances
     * @return Adapter A promise that resolves with the first fulfilled value
     */
    public static function any(array $promises): Adapter
    {
        return static::getAdapter()::any($promises);
    }

    /**
     * Create a promise that is already resolved with the given value.
     *
     * @param mixed $value The value to resolve with
     * @return Adapter A resolved promise
     */
    public static function resolve(mixed $value): Adapter
    {
        return static::getAdapter()::resolve($value);
    }

    /**
     * Create a promise that is already rejected with the given reason.
     *
     * @param mixed $reason The rejection reason (typically an Exception)
     * @return Adapter A rejected promise
     */
    public static function reject(mixed $reason): Adapter
    {
        return static::getAdapter()::reject($reason);
    }
}
