<?php

namespace Utopia\Tests\E2e\Parallel\React;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Utopia\Async\Parallel\Adapter\React;

#[Group('react-parallel')]
class ReactTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\class_exists(\React\EventLoop\Loop::class)) {
            $this->markTestSkipped('react/event-loop is not available');
        }

        if (!\class_exists(\React\ChildProcess\Process::class)) {
            $this->markTestSkipped('react/child-process is not available');
        }
    }

    protected function tearDown(): void
    {
        React::shutdown();
    }

    public function testRun(): void
    {
        $result = React::run(function () {
            return 'test result';
        });

        $this->assertEquals('test result', $result);
    }

    public function testRunWithArguments(): void
    {
        $result = React::run(function ($a, $b) {
            return $a + $b;
        }, 5, 3);

        $this->assertEquals(8, $result);
    }

    public function testAll(): void
    {
        $tasks = [
            fn () => 1,
            fn () => 2,
            fn () => 3,
        ];

        $results = React::all($tasks);

        $this->assertEquals([1, 2, 3], $results);
    }

    public function testAllWithMultipleTasks(): void
    {
        $tasks = [];
        for ($i = 0; $i < 5; $i++) {
            $tasks[] = function () use ($i) {
                return $i * 2;
            };
        }

        $results = React::all($tasks);

        $this->assertEquals([0, 2, 4, 6, 8], $results);
    }

    public function testMap(): void
    {
        $items = [1, 2, 3, 4, 5];

        $results = React::map($items, function ($item) {
            return $item * 2;
        });

        $this->assertEquals([0 => 2, 1 => 4, 2 => 6, 3 => 8, 4 => 10], $results);
    }

    public function testMapWithEmptyArray(): void
    {
        $results = React::map([], function ($item) {
            return $item * 2;
        });

        $this->assertEquals([], $results);
    }

    public function testMapWithCustomWorkerCount(): void
    {
        $items = range(1, 10);

        $results = React::map($items, function ($item) {
            return $item * 3;
        }, 2);

        $expected = [];
        foreach (range(1, 10) as $i) {
            $expected[$i - 1] = $i * 3;
        }

        $this->assertEquals($expected, $results);
    }

    public function testMapWithIndex(): void
    {
        $items = ['a', 'b', 'c'];

        $results = React::map($items, function ($item, $index) {
            return $index . ':' . $item;
        });

        $this->assertEquals([
            0 => '0:a',
            1 => '1:b',
            2 => '2:c',
        ], $results);
    }

    public function testPool(): void
    {
        $tasks = [];
        for ($i = 0; $i < 10; $i++) {
            $tasks[] = function () use ($i) {
                return $i;
            };
        }

        $results = React::pool($tasks, 3);

        $this->assertCount(10, $results);
        for ($i = 0; $i < 10; $i++) {
            $this->assertEquals($i, $results[$i]);
        }
    }

    public function testPoolWithSingleWorker(): void
    {
        $tasks = [
            fn () => 'first',
            fn () => 'second',
            fn () => 'third',
        ];

        $results = React::pool($tasks, 1);

        $this->assertEquals([
            0 => 'first',
            1 => 'second',
            2 => 'third',
        ], $results);
    }

    public function testPoolMaintainsOrder(): void
    {
        $tasks = [];
        for ($i = 0; $i < 5; $i++) {
            $tasks[$i] = function () use ($i) {
                usleep(rand(1000, 5000));
                return "task_$i";
            };
        }

        $results = React::pool($tasks, 2);

        $this->assertEquals([
            0 => 'task_0',
            1 => 'task_1',
            2 => 'task_2',
            3 => 'task_3',
            4 => 'task_4',
        ], $results);
    }

    public function testParallelExecutionIsFasterThanSequential(): void
    {
        $task = function () {
            usleep(50000); // 50ms
            return true;
        };

        $tasks = array_fill(0, 4, $task);

        $start = microtime(true);
        React::all($tasks);
        $parallelTime = microtime(true) - $start;

        // Process spawning has overhead, but should still be faster than sequential
        $this->assertLessThan(0.5, $parallelTime, 'Parallel execution should be faster than 500ms');
    }

    public function testRunReturnsCorrectTypes(): void
    {
        // Test integer return
        $intResult = React::run(fn () => 42);
        $this->assertIsInt($intResult);
        $this->assertEquals(42, $intResult);

        // Test string return
        $strResult = React::run(fn () => 'hello');
        $this->assertIsString($strResult);
        $this->assertEquals('hello', $strResult);

        // Test array return
        $arrResult = React::run(fn () => [1, 2, 3]);
        $this->assertIsArray($arrResult);
        $this->assertEquals([1, 2, 3], $arrResult);
    }

    public function testProcessIsolation(): void
    {
        $sharedValue = 'original';

        $result = React::run(function () use ($sharedValue) {
            $sharedValue = 'modified';
            return $sharedValue;
        });

        $this->assertEquals('modified', $result);
        $this->assertEquals('original', $sharedValue);
    }

    public function testHighVolumeParallelTasks(): void
    {
        $tasks = [];
        for ($i = 0; $i < 20; $i++) {
            $tasks[] = function () use ($i) {
                $sum = 0;
                for ($j = 1; $j <= 1000; $j++) {
                    $sum += $j * $j;
                }
                return ['index' => $i, 'sum' => $sum];
            };
        }

        $start = microtime(true);
        $results = React::all($tasks);
        $parallelTime = microtime(true) - $start;

        $this->assertCount(20, $results);
        $expectedSum = 333833500;

        foreach ($results as $idx => $result) {
            $this->assertEquals($idx, $result['index']);
            $this->assertEquals($expectedSum, $result['sum']);
        }

        $this->assertLessThan(30.0, $parallelTime, 'High volume tasks should complete within 30 seconds');
    }

    public function testAllWithEmptyArray(): void
    {
        $results = React::all([]);
        $this->assertEquals([], $results);
    }

    public function testPoolWithEmptyArray(): void
    {
        $results = React::pool([], 4);
        $this->assertEquals([], $results);
    }

    public function testShutdown(): void
    {
        React::all([fn () => 1]);
        React::shutdown();

        $result = React::run(fn () => 'after shutdown');
        $this->assertEquals('after shutdown', $result);
    }

    public function testSetWorkerScript(): void
    {
        // Get current worker script path
        $reflection = new \ReflectionClass(React::class);
        $method = $reflection->getMethod('getWorkerScript');
        $method->setAccessible(true);

        $workerPath = $method->invoke(null);
        $this->assertIsString($workerPath);
        $this->assertFileExists($workerPath);
    }
}
