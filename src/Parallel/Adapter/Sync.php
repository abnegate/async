<?php

namespace Utopia\Async\Parallel\Adapter;

use Utopia\Async\Parallel\Adapter;

/**
 * Synchronous Parallel Adapter (fallback).
 *
 * Executes tasks sequentially when no async runtime is available.
 *
 * @internal Use Utopia\Async\Parallel facade instead
 * @package Utopia\Async\Parallel\Adapter
 */
class Sync extends Adapter
{
    /**
     * Sync adapter is always supported as it has no dependencies.
     *
     * @return bool Always returns true
     */
    public static function isSupported(): bool
    {
        return true;
    }

    public static function run(callable $task, ...$args): mixed
    {
        return $task(...$args);
    }

    public static function all(array $tasks): array
    {
        $results = [];
        foreach ($tasks as $task) {
            $results[] = $task();
        }
        return $results;
    }

    public static function map(array $items, callable $callback, ?int $workers = null): array
    {
        $results = [];
        foreach ($items as $index => $item) {
            $results[$index] = $callback($item, $index);
        }
        return $results;
    }

    public static function forEach(array $items, callable $callback, ?int $workers = null): void
    {
        foreach ($items as $index => $item) {
            $callback($item, $index);
        }
    }

    public static function pool(array $tasks, int $maxConcurrency): array
    {
        $results = [];
        foreach ($tasks as $task) {
            $results[] = $task();
        }
        return $results;
    }

    /**
     * Shutdown - no resources to clean up for sync adapter.
     *
     * @return void
     */
    public static function shutdown(): void
    {
    }
}
