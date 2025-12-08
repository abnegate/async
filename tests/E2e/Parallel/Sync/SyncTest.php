<?php

namespace Utopia\Tests\E2e\Parallel\Sync;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Utopia\Async\Parallel\Adapter\Sync;

#[Group('sync-parallel')]
class SyncTest extends TestCase
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
        $result = Sync::run(function ($a, $b) {
            return $a + $b;
        }, 5, 3);

        $this->assertEquals(8, $result);
    }

    public function testRunWithVariousTypes(): void
    {
        // Integer
        $this->assertEquals(42, Sync::run(fn () => 42));

        // String
        $this->assertEquals('hello', Sync::run(fn () => 'hello'));

        // Array
        $this->assertEquals([1, 2, 3], Sync::run(fn () => [1, 2, 3]));

        // Null
        $this->assertNull(Sync::run(fn () => null));

        // Boolean
        $this->assertTrue(Sync::run(fn () => true));
        $this->assertFalse(Sync::run(fn () => false));
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

        $results = Sync::map($items, function ($item) {
            return $item * 2;
        });

        $this->assertEquals([2, 4, 6, 8, 10], $results);
    }

    public function testMapWithIndex(): void
    {
        $items = ['a', 'b', 'c'];

        $results = Sync::map($items, function ($item, $index) {
            return $index . ':' . $item;
        });

        $this->assertEquals(['0:a', '1:b', '2:c'], $results);
    }

    public function testMapWithEmptyArray(): void
    {
        $results = Sync::map([], function ($item) {
            return $item * 2;
        });

        $this->assertEquals([], $results);
    }

    public function testMapWithWorkerParameter(): void
    {
        $items = [1, 2, 3];

        // Workers parameter is ignored in sync mode
        $results = Sync::map($items, fn ($item) => $item * 2, 4);

        $this->assertEquals([2, 4, 6], $results);
    }

    public function testMapWithNullWorkers(): void
    {
        $items = [1, 2, 3];

        $results = Sync::map($items, fn ($item) => $item * 2, null);

        $this->assertEquals([2, 4, 6], $results);
    }

    public function testForEach(): void
    {
        $items = [1, 2, 3];
        $collected = [];

        Sync::forEach($items, function ($item) use (&$collected) {
            $collected[] = $item * 2;
        });

        $this->assertEquals([2, 4, 6], $collected);
    }

    public function testForEachWithIndex(): void
    {
        $items = ['a', 'b', 'c'];
        $collected = [];

        Sync::forEach($items, function ($item, $index) use (&$collected) {
            $collected[] = $index . ':' . $item;
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

        // Workers parameter is ignored in sync mode
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
            fn () => 'first',
            fn () => 'second',
            fn () => 'third',
        ];

        // maxConcurrency is ignored in sync mode
        $results = Sync::pool($tasks, 2);

        $this->assertEquals(['first', 'second', 'third'], $results);
    }

    public function testPoolWithEmptyArray(): void
    {
        $results = Sync::pool([], 4);
        $this->assertEquals([], $results);
    }

    public function testPoolPreservesOrder(): void
    {
        $tasks = [];
        for ($i = 0; $i < 5; $i++) {
            $tasks[] = function () use ($i) {
                return "task_$i";
            };
        }

        $results = Sync::pool($tasks, 2);

        $this->assertEquals([
            'task_0',
            'task_1',
            'task_2',
            'task_3',
            'task_4',
        ], $results);
    }

    public function testPoolIgnoresConcurrency(): void
    {
        $executionOrder = [];

        $tasks = [
            function () use (&$executionOrder) {
                $executionOrder[] = 1;
                return 1;
            },
            function () use (&$executionOrder) {
                $executionOrder[] = 2;
                return 2;
            },
            function () use (&$executionOrder) {
                $executionOrder[] = 3;
                return 3;
            },
        ];

        // Even with concurrency of 1, sync executes sequentially
        $results = Sync::pool($tasks, 1);

        $this->assertEquals([1, 2, 3], $results);
        $this->assertEquals([1, 2, 3], $executionOrder);
    }

    public function testRunWithException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Test exception');

        Sync::run(function () {
            throw new \RuntimeException('Test exception');
        });
    }

    public function testAllWithException(): void
    {
        $this->expectException(\RuntimeException::class);

        Sync::all([
            fn () => 1,
            fn () => throw new \RuntimeException('Error'),
            fn () => 3,
        ]);
    }

    public function testMapWithException(): void
    {
        $this->expectException(\RuntimeException::class);

        Sync::map([1, 2, 3], function ($item) {
            if ($item === 2) {
                throw new \RuntimeException('Error on item 2');
            }
            return $item;
        });
    }

    public function testSyncAdapterIsActuallySynchronous(): void
    {
        $order = [];

        $tasks = [
            function () use (&$order) {
                $order[] = 'task1_start';
                usleep(10000); // 10ms
                $order[] = 'task1_end';
                return 1;
            },
            function () use (&$order) {
                $order[] = 'task2_start';
                usleep(10000); // 10ms
                $order[] = 'task2_end';
                return 2;
            },
        ];

        Sync::all($tasks);

        // In sync mode, tasks run sequentially
        $this->assertEquals([
            'task1_start',
            'task1_end',
            'task2_start',
            'task2_end',
        ], $order);
    }
}
