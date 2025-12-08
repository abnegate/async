<?php

namespace Utopia\Tests\E2e\Parallel\Amp;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Utopia\Async\Parallel\Adapter\Amp;

#[Group('amp-parallel')]
class AmpTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\function_exists('Amp\Parallel\Worker\workerPool')) {
            $this->markTestSkipped('amphp/parallel is not available');
        }
    }

    protected function tearDown(): void
    {
        Amp::shutdown();
    }

    public function testRun(): void
    {
        $result = Amp::run(function () {
            return 'test result';
        });

        $this->assertEquals('test result', $result);
    }

    public function testRunWithArguments(): void
    {
        $result = Amp::run(function ($a, $b) {
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

        $results = Amp::all($tasks);

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

        $results = Amp::all($tasks);

        $this->assertEquals([0, 2, 4, 6, 8], $results);
    }

    public function testMap(): void
    {
        $items = [1, 2, 3, 4, 5];

        $results = Amp::map($items, function ($item) {
            return $item * 2;
        });

        $this->assertEquals([0 => 2, 1 => 4, 2 => 6, 3 => 8, 4 => 10], $results);
    }

    public function testMapWithEmptyArray(): void
    {
        $results = Amp::map([], function ($item) {
            return $item * 2;
        });

        $this->assertEquals([], $results);
    }

    public function testMapWithCustomWorkerCount(): void
    {
        $items = range(1, 10);

        $results = Amp::map($items, function ($item) {
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

        $results = Amp::map($items, function ($item, $index) {
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

        $results = Amp::pool($tasks, 3);

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

        $results = Amp::pool($tasks, 1);

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

        $results = Amp::pool($tasks, 2);

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
        Amp::all($tasks);
        $parallelTime = microtime(true) - $start;

        // Parallel execution should be significantly faster
        $this->assertLessThan(0.3, $parallelTime, 'Parallel execution should be faster than 300ms');
    }

    public function testRunReturnsCorrectTypes(): void
    {
        // Test integer return
        $intResult = Amp::run(fn () => 42);
        $this->assertIsInt($intResult);
        $this->assertEquals(42, $intResult);

        // Test string return
        $strResult = Amp::run(fn () => 'hello');
        $this->assertIsString($strResult);
        $this->assertEquals('hello', $strResult);

        // Test array return
        $arrResult = Amp::run(fn () => [1, 2, 3]);
        $this->assertIsArray($arrResult);
        $this->assertEquals([1, 2, 3], $arrResult);
    }

    public function testHighVolumeParallelTasks(): void
    {
        $tasks = [];
        for ($i = 0; $i < 50; $i++) {
            $tasks[] = function () use ($i) {
                $sum = 0;
                for ($j = 1; $j <= 1000; $j++) {
                    $sum += $j * $j;
                }
                return ['index' => $i, 'sum' => $sum];
            };
        }

        $start = microtime(true);
        $results = Amp::all($tasks);
        $parallelTime = microtime(true) - $start;

        $this->assertCount(50, $results);
        $expectedSum = 333833500; // Sum of squares from 1 to 1000

        foreach ($results as $idx => $result) {
            $this->assertEquals($idx, $result['index']);
            $this->assertEquals($expectedSum, $result['sum']);
        }

        $this->assertLessThan(10.0, $parallelTime, 'High volume tasks should complete within 10 seconds');
    }

    public function testAllWithEmptyArray(): void
    {
        $results = Amp::all([]);
        $this->assertEquals([], $results);
    }

    public function testPoolWithEmptyArray(): void
    {
        $results = Amp::pool([], 4);
        $this->assertEquals([], $results);
    }

    public function testGetPool(): void
    {
        $pool = Amp::getPool();
        $this->assertInstanceOf(\Amp\Parallel\Worker\WorkerPool::class, $pool);
    }

    public function testShutdown(): void
    {
        // Create some work to ensure pool is started
        Amp::all([fn () => 1]);

        // Shutdown should not throw
        Amp::shutdown();

        // Should be able to create new pool after shutdown
        $result = Amp::run(fn () => 'after shutdown');
        $this->assertEquals('after shutdown', $result);
    }
}
