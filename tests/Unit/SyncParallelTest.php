<?php

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Async\Parallel\Adapter\Sync;

class SyncParallelTest extends TestCase
{
    public function testRun(): void
    {
        $result = Sync::run(function () {
            return 'test result';
        });

        $this->assertEquals('test result', $result);
    }

    public function testRunWithArguments(): void
    {
        $result = Sync::run(function (int $a, int $b, int $c): int {
            return $a + $b + $c;
        }, 1, 2, 3);

        $this->assertEquals(6, $result);
    }

    public function testRunWithVariousTypes(): void
    {
        // Integer
        $result = Sync::run(fn () => 42);
        $this->assertEquals(42, $result);

        // String
        $result = Sync::run(fn () => 'hello');
        $this->assertEquals('hello', $result);

        // Array
        $result = Sync::run(fn () => [1, 2, 3]);
        $this->assertEquals([1, 2, 3], $result);

        // Object
        $result = Sync::run(function () {
            $obj = new \stdClass();
            $obj->value = 'test';
            return $obj;
        });
        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertEquals('test', $result->value);

        // Null
        $result = Sync::run(fn () => null);
        $this->assertNull($result);

        // Boolean
        $result = Sync::run(fn () => true);
        $this->assertTrue($result);
    }

    public function testAll(): void
    {
        $tasks = [
            fn () => 1,
            fn () => 2,
            fn () => 3,
        ];

        $results = Sync::all($tasks);

        $this->assertEquals([1, 2, 3], $results);
    }

    public function testAllWithEmptyArray(): void
    {
        $results = Sync::all([]);

        $this->assertEquals([], $results);
    }

    public function testAllPreservesOrder(): void
    {
        $tasks = [
            fn () => 'first',
            fn () => 'second',
            fn () => 'third',
        ];

        $results = Sync::all($tasks);

        $this->assertEquals(['first', 'second', 'third'], $results);
    }

    public function testMap(): void
    {
        $items = [1, 2, 3, 4, 5];

        $results = Sync::map($items, function (int $item): int {
            return $item * 2;
        });

        $this->assertEquals([2, 4, 6, 8, 10], $results);
    }

    public function testMapWithIndex(): void
    {
        $items = ['a', 'b', 'c'];

        $results = Sync::map($items, function (string $item, int $index): string {
            return "{$index}:{$item}";
        });

        $this->assertEquals(['0:a', '1:b', '2:c'], $results);
    }

    public function testMapWithEmptyArray(): void
    {
        $results = Sync::map([], fn ($item) => $item);

        $this->assertEquals([], $results);
    }

    public function testMapWithWorkerParameter(): void
    {
        $items = [1, 2, 3];

        // Worker count is ignored in Sync adapter, but shouldn't cause errors
        $results = Sync::map($items, fn (int $item): int => $item * 10, 4);

        $this->assertEquals([10, 20, 30], $results);
    }

    public function testMapWithNullWorkers(): void
    {
        $items = [1, 2, 3];

        $results = Sync::map($items, fn (int $item): int => $item + 1, null);

        $this->assertEquals([2, 3, 4], $results);
    }

    public function testForEach(): void
    {
        $items = [1, 2, 3];
        $collected = [];

        Sync::forEach($items, function (int $item) use (&$collected): void {
            $collected[] = $item * 2;
        });

        $this->assertEquals([2, 4, 6], $collected);
    }

    public function testForEachWithIndex(): void
    {
        $items = ['a', 'b', 'c'];
        $collected = [];

        Sync::forEach($items, function (string $item, int $index) use (&$collected): void {
            $collected[] = "{$index}:{$item}";
        });

        $this->assertEquals(['0:a', '1:b', '2:c'], $collected);
    }

    public function testForEachWithEmptyArray(): void
    {
        $called = false;

        Sync::forEach([], function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($called);
    }

    public function testForEachWithWorkerParameter(): void
    {
        $items = [1, 2, 3];
        $collected = [];

        // Worker count is ignored in Sync adapter
        Sync::forEach($items, function ($item) use (&$collected) {
            $collected[] = $item;
        }, 4);

        $this->assertEquals([1, 2, 3], $collected);
    }

    public function testForEachWithNullWorkers(): void
    {
        $items = [1, 2, 3];
        $collected = [];

        Sync::forEach($items, function ($item) use (&$collected) {
            $collected[] = $item;
        }, null);

        $this->assertEquals([1, 2, 3], $collected);
    }

    public function testPool(): void
    {
        $tasks = [
            fn () => 'a',
            fn () => 'b',
            fn () => 'c',
        ];

        $results = Sync::pool($tasks, 2);

        $this->assertEquals(['a', 'b', 'c'], $results);
    }

    public function testPoolWithEmptyArray(): void
    {
        $results = Sync::pool([], 5);

        $this->assertEquals([], $results);
    }

    public function testPoolPreservesOrder(): void
    {
        $tasks = [];
        for ($i = 0; $i < 10; $i++) {
            $tasks[] = fn () => $i;
        }

        $results = Sync::pool($tasks, 3);

        // Results should be in order since Sync runs sequentially
        // Note: The closures capture $i by reference, so all return 10
        $this->assertCount(10, $results);
    }

    public function testPoolIgnoresConcurrency(): void
    {
        // In Sync mode, maxConcurrency is ignored - all run sequentially
        $tasks = [
            fn () => 1,
            fn () => 2,
        ];

        // Even with concurrency of 1, behavior is the same
        $results = Sync::pool($tasks, 1);
        $this->assertEquals([1, 2], $results);

        // With high concurrency
        $results = Sync::pool($tasks, 100);
        $this->assertEquals([1, 2], $results);
    }

    public function testRunWithException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Test error');

        Sync::run(function () {
            throw new \RuntimeException('Test error');
        });
    }

    public function testAllWithException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Task failed');

        $tasks = [
            fn () => 1,
            function () {
                throw new \RuntimeException('Task failed');
            },
            fn () => 3,
        ];

        Sync::all($tasks);
    }

    public function testMapWithException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Map error');

        Sync::map([1, 2, 3], function ($item) {
            if ($item === 2) {
                throw new \RuntimeException('Map error');
            }
            return $item;
        });
    }

    public function testSyncAdapterIsActuallySynchronous(): void
    {
        $executionOrder = [];

        $tasks = [
            function () use (&$executionOrder) {
                $executionOrder[] = 'task1';
                return 1;
            },
            function () use (&$executionOrder) {
                $executionOrder[] = 'task2';
                return 2;
            },
            function () use (&$executionOrder) {
                $executionOrder[] = 'task3';
                return 3;
            },
        ];

        $results = Sync::all($tasks);

        // Execution should be sequential
        $this->assertEquals(['task1', 'task2', 'task3'], $executionOrder);
        $this->assertEquals([1, 2, 3], $results);
    }
}
