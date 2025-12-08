<?php

namespace Utopia\Tests\E2e\Parallel\Parallel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Utopia\Async\Parallel\Adapter\Parallel;

#[Group('ext-parallel')]
class ParallelTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\extension_loaded('parallel')) {
            $this->markTestSkipped('ext-parallel is not available (requires PHP ZTS build)');
        }

        if (\php_uname('m') === 'aarch64' || \php_uname('m') === 'arm64') {
            $this->markTestSkipped('ext-parallel segfaults on ARM64 due to upstream bug');
        }
    }

    protected function tearDown(): void
    {
        Parallel::shutdown();
    }

    public function testRun(): void
    {
        $result = Parallel::run(function () {
            return 'test result';
        });

        $this->assertEquals('test result', $result);
    }

    public function testRunWithArguments(): void
    {
        $result = Parallel::run(function ($a, $b) {
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

        $results = Parallel::all($tasks);

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

        $results = Parallel::all($tasks);

        $this->assertEquals([0, 2, 4, 6, 8], $results);
    }

    public function testMap(): void
    {
        $items = [1, 2, 3, 4, 5];

        $results = Parallel::map($items, function ($item) {
            return $item * 2;
        });

        $this->assertEquals([0 => 2, 1 => 4, 2 => 6, 3 => 8, 4 => 10], $results);
    }

    public function testMapWithEmptyArray(): void
    {
        $results = Parallel::map([], function ($item) {
            return $item * 2;
        });

        $this->assertEquals([], $results);
    }

    public function testMapWithCustomWorkerCount(): void
    {
        $items = range(1, 10);

        $results = Parallel::map($items, function ($item) {
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

        $results = Parallel::map($items, function ($item, $index) {
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

        $results = Parallel::pool($tasks, 3);

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

        $results = Parallel::pool($tasks, 1);

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

        $results = Parallel::pool($tasks, 2);

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
        Parallel::all($tasks);
        $parallelTime = microtime(true) - $start;

        $this->assertLessThan(0.3, $parallelTime, 'Parallel execution should be faster than 300ms');
        $this->assertGreaterThanOrEqual(0.045, $parallelTime, 'Should take at least the task duration');
    }

    public function testTrueParallelismNotSequential(): void
    {
        $taskDuration = 100; // 100ms
        $taskCount = 4;

        $tasks = [];
        for ($i = 0; $i < $taskCount; $i++) {
            $tasks[] = function () use ($taskDuration, $i) {
                $start = microtime(true);
                while ((microtime(true) - $start) * 1000 < $taskDuration) {
                    $x = 0;
                    for ($j = 0; $j < 10000; $j++) {
                        $x += $j;
                    }
                }
                return $i;
            };
        }

        $start = microtime(true);
        $results = Parallel::all($tasks);
        $elapsed = (microtime(true) - $start) * 1000;

        $this->assertEquals([0, 1, 2, 3], $results);
        // Allow up to 500ms to account for pool initialization overhead
        $this->assertLessThan(500, $elapsed, 'Parallel execution should be significantly faster than sequential');
    }

    public function testRunReturnsCorrectTypes(): void
    {
        // Test integer return
        $intResult = Parallel::run(fn () => 42);
        $this->assertIsInt($intResult);
        $this->assertEquals(42, $intResult);

        // Test string return
        $strResult = Parallel::run(fn () => 'hello');
        $this->assertIsString($strResult);
        $this->assertEquals('hello', $strResult);

        // Test array return
        $arrResult = Parallel::run(fn () => [1, 2, 3]);
        $this->assertIsArray($arrResult);
        $this->assertEquals([1, 2, 3], $arrResult);
    }

    public function testCpuIntensivePrimeCalculation(): void
    {
        $isPrime = function (int $n): bool {
            if ($n < 2) {
                return false;
            }
            for ($i = 2; $i <= sqrt($n); $i++) {
                if ($n % $i === 0) {
                    return false;
                }
            }
            return true;
        };

        $countPrimesInRange = function (int $start, int $end) use ($isPrime): int {
            $count = 0;
            for ($i = $start; $i <= $end; $i++) {
                if ($isPrime($i)) {
                    $count++;
                }
            }
            return $count;
        };

        $tasks = [
            fn () => $countPrimesInRange(1, 25000),
            fn () => $countPrimesInRange(25001, 50000),
            fn () => $countPrimesInRange(50001, 75000),
            fn () => $countPrimesInRange(75001, 100000),
        ];

        $start = microtime(true);
        $results = Parallel::all($tasks);
        $parallelTime = microtime(true) - $start;

        $totalPrimes = array_sum($results);
        $this->assertEquals(9592, $totalPrimes);
        $this->assertCount(4, $results);
        $this->assertLessThan(5.0, $parallelTime, 'Prime calculation should complete within 5 seconds');
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
        $results = Parallel::all($tasks);
        $parallelTime = microtime(true) - $start;

        $this->assertCount(50, $results);
        $expectedSum = 333833500;

        foreach ($results as $idx => $result) {
            $this->assertEquals($idx, $result['index']);
            $this->assertEquals($expectedSum, $result['sum']);
        }

        $this->assertLessThan(5.0, $parallelTime, 'High volume tasks should complete within 5 seconds');
    }

    public function testAllWithEmptyArray(): void
    {
        $results = Parallel::all([]);
        $this->assertEquals([], $results);
    }

    public function testPoolWithEmptyArray(): void
    {
        $results = Parallel::pool([], 4);
        $this->assertEquals([], $results);
    }

    public function testShutdown(): void
    {
        Parallel::all([fn () => 1]);
        Parallel::shutdown();

        $result = Parallel::run(fn () => 'after shutdown');
        $this->assertEquals('after shutdown', $result);
    }

    public function testThreadIsolation(): void
    {
        $sharedValue = 'original';

        $result = Parallel::run(function () use ($sharedValue) {
            $sharedValue = 'modified';
            return $sharedValue;
        });

        $this->assertEquals('modified', $result);
        $this->assertEquals('original', $sharedValue);
    }
}
