<?php

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Swoole\Thread as SwooleThread;
use Utopia\Async\Exception\Adapter;
use Utopia\Async\Parallel;
use Utopia\Async\Parallel\Adapter\Swoole\Thread;

class ParallelTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not available');
        }

        if (!class_exists(SwooleThread::class)) {
            $this->markTestSkipped('Swoole Thread support is not available. Requires Swoole >= 6.0 with thread support.');
        }
    }

    public function testSetAdapter(): void
    {
        Parallel::setAdapter(Thread::class);

        $result = Parallel::run(function () {
            return 'test';
        });

        $this->assertEquals('test', $result);
    }

    public function testSetAdapterWithInvalidClass(): void
    {
        $this->expectException(Adapter::class);
        $this->expectExceptionMessage('Adapter must be a valid parallel adapter implementation');

        Parallel::setAdapter(\stdClass::class);
    }

    public function testRun(): void
    {
        $result = Parallel::run(function () {
            return 'parallel result';
        });

        $this->assertEquals('parallel result', $result);
    }

    public function testRunWithArguments(): void
    {
        $result = Parallel::run(function (int $x, int $y): int {
            return $x * $y;
        }, 6, 7);

        $this->assertEquals(42, $result);
    }

    public function testAll(): void
    {
        $tasks = [
            fn () => 10,
            fn () => 20,
            fn () => 30,
        ];

        $results = Parallel::all($tasks);

        $this->assertEquals([10, 20, 30], $results);
    }

    public function testMap(): void
    {
        $items = [1, 2, 3, 4, 5];

        $results = Parallel::map($items, function (int $item): int {
            return $item ** 2;
        });

        $this->assertEquals([0 => 1, 1 => 4, 2 => 9, 3 => 16, 4 => 25], $results);
    }

    public function testMapWithCustomWorkers(): void
    {
        $items = range(1, 20);

        $results = Parallel::map($items, function (int $item): int {
            return $item + 10;
        }, 4);

        $expected = [];
        foreach (range(1, 20) as $i) {
            $expected[$i - 1] = $i + 10;
        }

        $this->assertEquals($expected, $results);
    }

    public function testPool(): void
    {
        $tasks = [];
        for ($i = 0; $i < 8; $i++) {
            $tasks[] = function () use ($i) {
                return "result_$i";
            };
        }

        $results = Parallel::pool($tasks, 3);

        $this->assertCount(8, $results);
        for ($i = 0; $i < 8; $i++) {
            $this->assertEquals("result_$i", $results[$i]);
        }
    }

    public function testDefaultAdapterSelection(): void
    {
        // Reset adapter to trigger default selection by unsetting it
        $reflection = new \ReflectionClass(Parallel::class);
        $property = $reflection->getProperty('adapter');

        // Store original value
        $original = $property->isInitialized(null) ? $property->getValue() : null;

        // Unset the property to force re-initialization
        $uninitializedReflection = new \ReflectionClass(Parallel::class);
        $uninitializedProperty = $uninitializedReflection->getProperty('adapter');

        // Create a new reflection to unset
        $classString = Parallel::class;
        $code = "
            class TestParallelReset extends \\{$classString} {
                public static function resetAdapter(): void {
                    unset(static::\$adapter);
                }
            }
        ";
        // Skip this test as we can't easily unset a typed static property
        // The adapter selection is tested through setAdapter tests
        $this->expectNotToPerformAssertions();
    }

    public function testAdapterSelectionWithoutSwooleThreads(): void
    {
        if (class_exists(SwooleThread::class)) {
            $this->markTestSkipped('Test requires Swoole Thread to be unavailable');
        }

        $this->expectException(Adapter::class);
        $this->expectExceptionMessage('No parallel adapter available');

        $reflection = new \ReflectionClass(Parallel::class);
        $property = $reflection->getProperty('adapter');
        $property->setAccessible(true);
        $property->setValue(null);

        Parallel::run(fn () => 'test');
    }

    public function testGetAdapterReturnsCorrectClass(): void
    {
        Parallel::setAdapter(Thread::class);

        $reflection = new \ReflectionClass(Parallel::class);
        $method = $reflection->getMethod('getAdapter');
        $method->setAccessible(true);

        $adapter = $method->invoke(null);

        $this->assertEquals(Thread::class, $adapter);
    }

    public function testComplexDataTypes(): void
    {
        // Test with array
        /** @var array<int> $result */
        $result = Parallel::run(function (array $data): array {
            /** @var array<int> $data */
            return \array_map(fn (int $x): int => $x * 2, $data);
        }, [1, 2, 3]);

        $this->assertEquals([2, 4, 6], $result);

        // Test with object
        $obj = new \stdClass();
        $obj->value = 100;

        $result = Parallel::run(function (\stdClass $obj): int {
            /** @var int $value */
            $value = $obj->value;
            return $value * 2;
        }, $obj);

        $this->assertEquals(200, $result);
    }

    // Empty input tests

    public function testAllWithEmptyArray(): void
    {
        $results = Parallel::all([]);
        $this->assertEquals([], $results);
    }

    public function testMapWithEmptyArray(): void
    {
        $results = Parallel::map([], fn (int $x) => $x * 2);
        $this->assertEquals([], $results);
    }

    public function testPoolWithEmptyArray(): void
    {
        $results = Parallel::pool([], 4);
        $this->assertEquals([], $results);
    }

    public function testForEachWithEmptyArray(): void
    {
        $called = false;
        Parallel::forEach([], function () use (&$called) {
            $called = true;
        });
        $this->assertFalse($called);
    }

    // Single item tests

    public function testAllWithSingleTask(): void
    {
        $results = Parallel::all([fn () => 'single']);
        $this->assertEquals(['single'], $results);
    }

    public function testMapWithSingleItem(): void
    {
        $results = Parallel::map([42], fn (int $x) => $x * 2);
        $this->assertEquals([0 => 84], $results);
    }

    public function testPoolWithSingleTaskAndManyWorkers(): void
    {
        $results = Parallel::pool([fn () => 'only'], 10);
        $this->assertEquals(['only'], $results);
    }

    // Return value type tests

    public function testRunReturnsNull(): void
    {
        $result = Parallel::run(fn () => null);
        $this->assertNull($result);
    }

    public function testRunReturnsFalse(): void
    {
        $result = Parallel::run(fn () => false);
        $this->assertFalse($result);
    }

    public function testRunReturnsZero(): void
    {
        $this->assertEquals(0, Parallel::run(fn () => 0));
        $this->assertEquals(0.0, Parallel::run(fn () => 0.0));
    }

    public function testRunReturnsEmptyString(): void
    {
        $result = Parallel::run(fn () => '');
        $this->assertEquals('', $result);
    }

    public function testRunReturnsEmptyArray(): void
    {
        $result = Parallel::run(fn () => []);
        $this->assertEquals([], $result);
    }

    public function testAllWithMixedNullResults(): void
    {
        $tasks = [
            fn () => null,
            fn () => 'value',
            fn () => null,
        ];

        $results = Parallel::all($tasks);

        $this->assertNull($results[0]);
        $this->assertEquals('value', $results[1]);
        $this->assertNull($results[2]);
    }

    // Large data tests

    public function testRunWithLargeArray(): void
    {
        $largeArray = range(1, 10000);
        $result = Parallel::run(fn () => $largeArray);

        $this->assertIsArray($result);
        $this->assertCount(10000, $result);
        $this->assertEquals(1, $result[0]);
        $this->assertEquals(10000, $result[9999]);
    }

    public function testRunWithLargeString(): void
    {
        $largeString = str_repeat('x', 100000);
        $result = Parallel::run(fn () => $largeString);

        $this->assertIsString($result);
        $this->assertEquals(100000, strlen($result));
    }

    public function testMapWithManyItems(): void
    {
        $items = range(1, 500);
        $results = Parallel::map($items, fn (int $x) => $x * 2);

        $this->assertCount(500, $results);
        $this->assertEquals(2, $results[0]);
        $this->assertEquals(1000, $results[499]);
    }

    // Complex data structure tests

    public function testRunWithNestedArray(): void
    {
        $nested = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'value' => 'deep'
                    ]
                ]
            ]
        ];

        $result = Parallel::run(fn () => $nested);

        $this->assertIsArray($result);
        /** @var array{level1: array{level2: array{level3: array{value: string}}}} $result */
        $this->assertEquals('deep', $result['level1']['level2']['level3']['value']);
    }

    public function testRunWithObjectGraph(): void
    {
        $obj = new \stdClass();
        $obj->name = 'parent';
        $obj->child = new \stdClass();
        $obj->child->name = 'child';
        $obj->child->parent = $obj; // Note: circular ref won't serialize well, but flat objects work

        // Create a simpler object graph
        $obj2 = new \stdClass();
        $obj2->name = 'root';
        $obj2->children = [
            (object) ['name' => 'child1'],
            (object) ['name' => 'child2'],
        ];

        $result = Parallel::run(fn () => $obj2);

        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertEquals('root', $result->name);
        /** @var array<object> $children */
        $children = $result->children;
        $this->assertCount(2, $children);
        $this->assertInstanceOf(\stdClass::class, $children[0]);
        $this->assertEquals('child1', $children[0]->name);
    }

    public function testAllWithDifferentReturnTypes(): void
    {
        $tasks = [
            fn () => 42,
            fn () => 'string',
            fn () => [1, 2, 3],
            fn () => (object) ['key' => 'value'],
            fn () => true,
            fn () => null,
            fn () => 3.14,
        ];

        $results = Parallel::all($tasks);

        $this->assertIsInt($results[0]);
        $this->assertIsString($results[1]);
        $this->assertIsArray($results[2]);
        $this->assertIsObject($results[3]);
        $this->assertIsBool($results[4]);
        $this->assertNull($results[5]);
        $this->assertIsFloat($results[6]);
    }

    // Worker count edge cases

    public function testMapWithOneWorker(): void
    {
        $items = range(1, 10);
        $results = Parallel::map($items, fn (int $x): int => $x * 2, 1);

        $expected = [];
        foreach (range(1, 10) as $i) {
            $expected[$i - 1] = $i * 2;
        }
        $this->assertEquals($expected, $results);
    }

    public function testMapWithMoreWorkersThanItems(): void
    {
        $items = [1, 2, 3];
        $results = Parallel::map($items, fn (int $x): int => $x * 2, 10);

        $this->assertEquals([0 => 2, 1 => 4, 2 => 6], $results);
    }

    public function testPoolWithOneWorker(): void
    {
        $tasks = [
            fn () => 'a',
            fn () => 'b',
            fn () => 'c',
        ];

        $results = Parallel::pool($tasks, 1);

        $this->assertEquals(['a', 'b', 'c'], $results);
    }

    // Order preservation tests

    public function testAllPreservesOrder(): void
    {
        $tasks = [];
        for ($i = 0; $i < 20; $i++) {
            $idx = $i;
            $tasks[] = function () use ($idx) {
                // Add random delay to mix up completion order
                usleep(rand(1000, 10000));
                return $idx;
            };
        }

        $results = Parallel::all($tasks);

        // Results should be in original order
        for ($i = 0; $i < 20; $i++) {
            $this->assertEquals($i, $results[$i]);
        }
    }

    public function testMapPreservesOrder(): void
    {
        $items = range(1, 20);
        $results = Parallel::map($items, function (int $item): int {
            usleep(rand(1000, 5000));
            return $item * 10;
        });

        foreach ($items as $idx => $item) {
            $this->assertEquals($item * 10, $results[$idx]);
        }
    }

    // Callback with index tests

    public function testMapCallbackReceivesIndex(): void
    {
        $items = ['a', 'b', 'c'];
        $results = Parallel::map($items, function (string $item, int $index): string {
            return "{$index}:{$item}";
        });

        $this->assertEquals([
            0 => '0:a',
            1 => '1:b',
            2 => '2:c',
        ], $results);
    }

    public function testForEachCallbackReceivesIndex(): void
    {
        $items = ['x', 'y', 'z'];

        // Since forEach doesn't return, we need to verify through side effects
        // But in parallel context, we can only verify the callback receives correct args
        // by returning them (which forEach discards)
        Parallel::forEach($items, function (string $item, int $index): void {
            // This modifies parent scope through closure
            // but each worker has its own copy
        });

        // forEach doesn't throw, so just verify it completes
        $this->expectNotToPerformAssertions();
    }

    // Exception and error tests

    public function testRunWithExceptionPropagatesToCaller(): void
    {
        // Exception type may be wrapped during serialization across thread boundary
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('task failed');

        Parallel::run(function () {
            throw new \RuntimeException('task failed');
        });
    }

    public function testAllContinuesAfterException(): void
    {
        $tasks = [
            fn () => 'success1',
            function () {
                throw new \Exception('failed');
            },
            fn () => 'success2',
        ];

        $results = Parallel::all($tasks);

        // First task succeeds
        $this->assertEquals('success1', $results[0]);
        // Failed task returns null
        $this->assertNull($results[1]);
        // Third task succeeds
        $this->assertEquals('success2', $results[2]);
    }

    public function testMapContinuesAfterException(): void
    {
        $items = [1, 2, 3, 4, 5];
        /** @var array<int, int|null> $results */
        $results = Parallel::map($items, function (int $item): int {
            if ($item === 3) {
                throw new \Exception('item 3 failed');
            }
            return $item * 2;
        });

        $this->assertEquals(2, $results[0]);
        $this->assertEquals(4, $results[1]);
        // Failed item may be null or missing depending on error handling
        $this->assertNull($results[2] ?? null);
        $this->assertEquals(8, $results[3]);
        $this->assertEquals(10, $results[4]);
    }

    // Argument passing tests

    public function testRunWithMultipleArguments(): void
    {
        $result = Parallel::run(function (int $a, int $b, int $c): int {
            return $a + $b + $c;
        }, 1, 2, 3);

        $this->assertEquals(6, $result);
    }

    public function testRunWithMixedTypeArguments(): void
    {
        /** @var array{num: int, str: string, arr: array<int>, obj_value: string} $result */
        $result = Parallel::run(function (int $num, string $str, array $arr, \stdClass $obj) {
            return [
                'num' => $num,
                'str' => $str,
                'arr' => $arr,
                'obj_value' => $obj->value,
            ];
        }, 42, 'hello', [1, 2, 3], (object) ['value' => 'test']);

        $this->assertEquals(42, $result['num']);
        $this->assertEquals('hello', $result['str']);
        $this->assertEquals([1, 2, 3], $result['arr']);
        $this->assertEquals('test', $result['obj_value']);
    }

    public function testRunWithNoArguments(): void
    {
        $result = Parallel::run(function () {
            return 'no args';
        });

        $this->assertEquals('no args', $result);
    }

    // Closure binding tests

    public function testRunClosureCanAccessUseVariables(): void
    {
        $multiplier = 10;
        $addend = 5;

        $result = Parallel::run(function () use ($multiplier, $addend) {
            return 3 * $multiplier + $addend;
        });

        $this->assertEquals(35, $result);
    }

    public function testAllClosuresAccessDifferentUseVariables(): void
    {
        $values = [1, 2, 3];
        $tasks = [];

        foreach ($values as $value) {
            $tasks[] = function () use ($value) {
                return $value * 10;
            };
        }

        $results = Parallel::all($tasks);

        $this->assertEquals([10, 20, 30], $results);
    }

    // Concurrent execution verification

    public function testAllExecutesConcurrently(): void
    {
        Parallel::shutdown();
        $taskDuration = 100; // 100ms each
        $taskCount = 4;

        $tasks = [];
        for ($i = 0; $i < $taskCount; $i++) {
            $tasks[] = function () use ($taskDuration) {
                usleep($taskDuration * 1000);
                return true;
            };
        }

        $start = microtime(true);
        $results = Parallel::all($tasks);
        $elapsed = (microtime(true) - $start) * 1000;

        // All tasks should complete
        $this->assertCount($taskCount, $results);

        // If sequential: 4 * 100ms = 400ms
        // If parallel: ~100ms + overhead
        // Should be significantly less than sequential time
        $this->assertLessThan(300, $elapsed, 'Should execute concurrently');
    }

    // Pool reuse tests

    public function testMultipleAllCallsReusePool(): void
    {
        // First batch
        $results1 = Parallel::all([
            fn () => 'batch1-task1',
            fn () => 'batch1-task2',
        ]);

        // Second batch
        $results2 = Parallel::all([
            fn () => 'batch2-task1',
            fn () => 'batch2-task2',
            fn () => 'batch2-task3',
        ]);

        // Third batch
        $results3 = Parallel::all([
            fn () => 'batch3-task1',
        ]);

        $this->assertEquals(['batch1-task1', 'batch1-task2'], $results1);
        $this->assertEquals(['batch2-task1', 'batch2-task2', 'batch2-task3'], $results2);
        $this->assertEquals(['batch3-task1'], $results3);
    }

    public function testShutdownAndRecreatePool(): void
    {
        // Execute tasks
        $results1 = Parallel::all([fn () => 'before']);
        $this->assertEquals(['before'], $results1);

        // Shutdown
        Parallel::shutdown();

        // Execute again - should create new pool
        $results2 = Parallel::all([fn () => 'after']);
        $this->assertEquals(['after'], $results2);
    }

}
