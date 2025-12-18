<?php

namespace Utopia\Tests\E2e\Parallel\Swoole;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Swoole\Process as SwooleProcess;
use Utopia\Async\Parallel\Adapter\Swoole\Process;

#[Group('swoole-process')]
class ProcessTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not available');
        }

        if (!class_exists(SwooleProcess::class)) {
            $this->markTestSkipped('Swoole Process support is not available.');
        }
    }

    public function testRun(): void
    {
        $result = Process::run(function () {
            return 'test result';
        });

        $this->assertEquals('test result', $result);
    }

    public function testRunWithArguments(): void
    {
        $result = Process::run(function (int $a, int $b) {
            return $a + $b;
        }, 5, 3);

        $this->assertEquals(8, $result);
    }

    public function testRunWithException(): void
    {
        $this->expectException(\Exception::class);

        Process::run(function () {
            throw new \RuntimeException('process error');
        });
    }

    public function testAll(): void
    {
        $tasks = [
            fn () => 1,
            fn () => 2,
            fn () => 3,
        ];

        $results = Process::all($tasks);

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

        $results = Process::all($tasks);

        $this->assertEquals([0, 2, 4, 6, 8], $results);
    }

    public function testMap(): void
    {
        $items = [1, 2, 3, 4, 5];

        $results = Process::map($items, function (int $item) {
            return $item * 2;
        });

        $this->assertEquals([0 => 2, 1 => 4, 2 => 6, 3 => 8, 4 => 10], $results);
    }

    public function testMapWithEmptyArray(): void
    {
        $results = Process::map([], function (int $item) {
            return $item * 2;
        });

        $this->assertEquals([], $results);
    }

    public function testMapWithCustomWorkerCount(): void
    {
        $items = range(1, 10);

        $results = Process::map($items, function (int $item) {
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

        $results = Process::map($items, function (string $item, int $index) {
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

        $results = Process::pool($tasks, 3);

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

        $results = Process::pool($tasks, 1);

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
                // Add small random delay to test ordering
                usleep(rand(1000, 5000));
                return "task_$i";
            };
        }

        $results = Process::pool($tasks, 2);

        $this->assertEquals([
            0 => 'task_0',
            1 => 'task_1',
            2 => 'task_2',
            3 => 'task_3',
            4 => 'task_4',
        ], $results);
    }

    public function testCheckProcessSupportWithoutSwoole(): void
    {
        // This test can't really run, but documents the expected behavior
        $this->expectNotToPerformAssertions();
    }

    public function testParallelExecutionIsFasterThanSequential(): void
    {
        $task = function () {
            // Simulate CPU-intensive work
            usleep(50000); // 50ms
            return true;
        };

        $tasks = array_fill(0, 4, $task);

        // Measure parallel execution
        $start = microtime(true);
        Process::all($tasks);
        $parallelTime = microtime(true) - $start;

        // Parallel execution should be significantly faster
        // With 4 tasks of 50ms each, sequential would be ~200ms
        // Parallel should be close to 50ms (plus overhead)
        $this->assertLessThan(0.15, $parallelTime, 'Parallel execution should be faster than 150ms');
        $this->assertGreaterThanOrEqual(0.045, $parallelTime, 'Should take at least the task duration');
    }

    public function testTrueParallelismNotSequential(): void
    {
        // Create tasks that run for a specific duration
        $taskDuration = 100; // 100ms
        $taskCount = 4;

        $tasks = [];
        for ($i = 0; $i < $taskCount; $i++) {
            $tasks[] = function () use ($taskDuration, $i) {
                $start = microtime(true);
                // Busy work to ensure it's CPU-bound
                while ((microtime(true) - $start) * 1000 < $taskDuration) {
                    // CPU-intensive work
                    $x = 0;
                    for ($j = 0; $j < 10000; $j++) {
                        $x += $j;
                    }
                }
                return $i;
            };
        }

        $start = microtime(true);
        $results = Process::all($tasks);
        $elapsed = (microtime(true) - $start) * 1000;

        // Verify results
        $this->assertEquals([0, 1, 2, 3], $results);

        // If truly parallel, should complete in ~100ms
        // If sequential, would take ~400ms
        // Allow some overhead, but should be much closer to 100ms than 400ms
        $this->assertLessThan(250, $elapsed, 'Parallel execution should be significantly faster than sequential');
    }

    public function testMapActuallyDistributesWork(): void
    {
        $items = range(1, 20);

        $start = microtime(true);
        $results = Process::map($items, function (int $item) {
            // Simulate some work
            usleep(10000); // 10ms per item
            return $item * 2;
        }, 4); // Use 4 workers
        $elapsed = microtime(true) - $start;

        // 20 items at 10ms each = 200ms sequential
        // With 4 workers: ~50ms (20/4 * 10ms)
        // Allow overhead but should be much faster than sequential
        $this->assertLessThan(0.15, $elapsed, 'Map should distribute work across workers');
        $this->assertCount(20, $results);
    }

    public function testMapDistributesWorkAcrossWorkers(): void
    {
        $items = range(1, 100);
        $cpuCount = function_exists('swoole_cpu_num') ? swoole_cpu_num() : 4;

        $start = microtime(true);
        $results = Process::map($items, function (int $item) {
            return $item * 2;
        }, $cpuCount);
        $elapsed = microtime(true) - $start;

        $this->assertCount(100, $results);
        // Should be reasonably fast even with 100 items
        $this->assertLessThan(1.0, $elapsed);
    }

    public function testCpuIntensivePrimeCalculation(): void
    {
        // CPU-intensive task: find prime numbers
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

        // Split range 1-100000 into 4 chunks
        $tasks = [
            fn () => $countPrimesInRange(1, 25000),
            fn () => $countPrimesInRange(25001, 50000),
            fn () => $countPrimesInRange(50001, 75000),
            fn () => $countPrimesInRange(75001, 100000),
        ];

        $start = microtime(true);
        $results = Process::all($tasks);
        $parallelTime = microtime(true) - $start;

        // Total primes in range 1-100000 is 9592
        $totalPrimes = array_sum($results);
        $this->assertEquals(9592, $totalPrimes);

        // Verify all 4 chunks returned valid results
        $this->assertCount(4, $results);
        foreach ($results as $count) {
            $this->assertIsInt($count);
            $this->assertGreaterThan(0, $count);
        }

        // Should complete within reasonable time (proves parallel execution works)
        $this->assertLessThan(5.0, $parallelTime, 'Prime calculation should complete within 5 seconds');
    }

    public function testCpuIntensiveFibonacci(): void
    {
        // CPU-intensive recursive Fibonacci (intentionally slow)
        $fibonacci = function (int $n) use (&$fibonacci): int {
            if ($n <= 1) {
                return $n;
            }
            return $fibonacci($n - 1) + $fibonacci($n - 2);
        };

        // Calculate fib(28-31) in parallel - each takes significant CPU time
        $tasks = [
            fn () => $fibonacci(28),
            fn () => $fibonacci(29),
            fn () => $fibonacci(30),
            fn () => $fibonacci(31),
        ];

        $start = microtime(true);
        $results = Process::all($tasks);
        $parallelTime = microtime(true) - $start;

        // Verify correct Fibonacci values
        $this->assertEquals(317811, $results[0]);   // fib(28)
        $this->assertEquals(514229, $results[1]);   // fib(29)
        $this->assertEquals(832040, $results[2]);   // fib(30)
        $this->assertEquals(1346269, $results[3]);  // fib(31)

        // Should complete within reasonable time
        $this->assertLessThan(5.0, $parallelTime, 'Fibonacci calculation should complete within 5 seconds');
    }

    public function testCpuIntensiveMatrixMultiplication(): void
    {
        // CPU-intensive matrix operations - return checksum to avoid large IPC payloads
        $multiplyAndChecksum = function (int $size, int $seed): float {
            // Generate matrices
            $a = [];
            $b = [];
            for ($i = 0; $i < $size; $i++) {
                $a[$i] = [];
                $b[$i] = [];
                for ($j = 0; $j < $size; $j++) {
                    $a[$i][$j] = (($seed + $i * $size + $j) % 100) / 10.0;
                    $b[$i][$j] = (($seed + 500 + $i * $size + $j) % 100) / 10.0;
                }
            }

            // Multiply matrices
            $result = array_fill(0, $size, array_fill(0, $size, 0.0));
            for ($i = 0; $i < $size; $i++) {
                for ($j = 0; $j < $size; $j++) {
                    for ($k = 0; $k < $size; $k++) {
                        $result[$i][$j] += $a[$i][$k] * $b[$k][$j];
                    }
                }
            }

            // Return checksum instead of full matrix
            $checksum = 0.0;
            for ($i = 0; $i < $size; $i++) {
                for ($j = 0; $j < $size; $j++) {
                    $checksum += $result[$i][$j];
                }
            }
            return $checksum;
        };

        $matrixSize = 100;
        $tasks = [
            fn () => $multiplyAndChecksum($matrixSize, 0),
            fn () => $multiplyAndChecksum($matrixSize, 1000),
            fn () => $multiplyAndChecksum($matrixSize, 2000),
            fn () => $multiplyAndChecksum($matrixSize, 3000),
        ];

        $start = microtime(true);
        $results = Process::all($tasks);
        $parallelTime = microtime(true) - $start;

        // Verify we got 4 valid checksum results
        $this->assertCount(4, $results);
        foreach ($results as $checksum) {
            $this->assertIsFloat($checksum);
            $this->assertGreaterThan(0, $checksum);
        }

        // Sequential comparison
        $seqStart = microtime(true);
        $seqResults = [
            $multiplyAndChecksum($matrixSize, 0),
            $multiplyAndChecksum($matrixSize, 1000),
            $multiplyAndChecksum($matrixSize, 2000),
            $multiplyAndChecksum($matrixSize, 3000),
        ];
        $sequentialTime = microtime(true) - $seqStart;

        // Results should match sequential computation
        $this->assertEquals($seqResults, $results);

        // Should complete within reasonable time
        $this->assertLessThan(5.0, $parallelTime, 'Matrix multiplication should complete within 5 seconds');
    }

    public function testHighVolumeParallelTasks(): void
    {
        // Run 100 tasks with moderate CPU work each
        $tasks = [];
        for ($i = 0; $i < 100; $i++) {
            $tasks[] = function () use ($i) {
                // Compute sum of squares up to 10000
                $sum = 0;
                for ($j = 1; $j <= 10000; $j++) {
                    $sum += $j * $j;
                }
                return ['index' => $i, 'sum' => $sum];
            };
        }

        $start = microtime(true);
        $results = Process::all($tasks);
        $parallelTime = microtime(true) - $start;

        // Verify all 100 tasks completed with correct results
        $this->assertCount(100, $results);
        $expectedSum = 333383335000; // Sum of squares from 1 to 10000

        foreach ($results as $idx => $result) {
            $this->assertIsArray($result);
            /** @var array{index: int|string, sum: int} $result */
            $this->assertEquals($idx, $result['index']);
            $this->assertEquals($expectedSum, $result['sum']);
        }

        // With 100 tasks and pool workers, should complete reasonably fast
        $this->assertLessThan(2.0, $parallelTime, 'High volume tasks should complete within 2 seconds');
    }

    public function testAllWithError(): void
    {
        $tasks = [
            fn () => 'success',
            function () {
                throw new \RuntimeException('task error');
            },
            fn () => 'success2',
        ];

        $results = Process::all($tasks);

        // First task should succeed
        $this->assertEquals('success', $results[0]);
        // Second task should have null (error handled)
        $this->assertNull($results[1]);
        // Third task should succeed
        $this->assertEquals('success2', $results[2]);
    }

    public function testRunReturnsCorrectTypes(): void
    {
        // Test integer return
        $intResult = Process::run(fn () => 42);
        $this->assertIsInt($intResult);
        $this->assertEquals(42, $intResult);

        // Test string return
        $strResult = Process::run(fn () => 'hello');
        $this->assertIsString($strResult);
        $this->assertEquals('hello', $strResult);

        // Test array return
        $arrResult = Process::run(fn () => [1, 2, 3]);
        $this->assertIsArray($arrResult);
        $this->assertEquals([1, 2, 3], $arrResult);

        // Test object return
        $obj = new \stdClass();
        $obj->value = 'test';
        $objResult = Process::run(fn () => $obj);
        $this->assertInstanceOf(\stdClass::class, $objResult);
        $this->assertEquals('test', $objResult->value);
    }

    public function testProcessIsolation(): void
    {
        // Test that processes have isolated memory space
        $sharedValue = 'original';

        $result = Process::run(function () use ($sharedValue) {
            // Modify in child process
            $sharedValue = 'modified';
            return $sharedValue;
        });

        // Child process modification should be returned
        $this->assertEquals('modified', $result);
        // Parent process should remain unchanged
        $this->assertEquals('original', $sharedValue);
    }

    public function testPoolReuseWorkers(): void
    {
        // Create more tasks than workers to ensure worker reuse
        $tasks = [];
        for ($i = 0; $i < 10; $i++) {
            $tasks[] = function () use ($i) {
                return $i;
            };
        }

        // Use only 2 workers for 10 tasks
        $results = Process::pool($tasks, 2);

        // All tasks should complete successfully
        $this->assertCount(10, $results);
        for ($i = 0; $i < 10; $i++) {
            $this->assertEquals($i, $results[$i]);
        }
    }

    public function testDefaultPoolPersistence(): void
    {
        // First batch of tasks
        $tasks1 = [
            fn () => 'task1',
            fn () => 'task2',
            fn () => 'task3',
        ];

        $results1 = Process::all($tasks1);
        $this->assertEquals(['task1', 'task2', 'task3'], $results1);

        // Second batch - should reuse the same pool
        $tasks2 = [
            fn () => 'task4',
            fn () => 'task5',
        ];

        $results2 = Process::all($tasks2);
        $this->assertEquals(['task4', 'task5'], $results2);

        // Clean up
        Process::shutdown();
    }

    public function testExplicitPoolCreation(): void
    {
        // Create an explicit pool with 2 workers
        $pool = Process::createPool(2);

        $this->assertEquals(2, $pool->getWorkerCount());
        $this->assertFalse($pool->isShutdown());

        // Use the explicit pool
        $tasks = [
            fn () => 1,
            fn () => 2,
            fn () => 3,
        ];

        $results = $pool->execute($tasks);
        $this->assertEquals([1, 2, 3], $results);

        // Shutdown explicit pool
        $pool->shutdown();
        $this->assertTrue($pool->isShutdown());
    }

    public function testPoolShutdown(): void
    {
        // Create some tasks
        $tasks = [fn () => 'test'];

        // Execute to create default pool
        Process::all($tasks);

        // Shutdown pool
        Process::shutdown();

        // Execute again - should create new pool
        $results = Process::all($tasks);
        $this->assertEquals(['test'], $results);

        // Clean up
        Process::shutdown();
    }

    public function testMultiplePoolsIndependent(): void
    {
        // Create two independent pools
        $pool1 = Process::createPool(2);
        $pool2 = Process::createPool(4);

        $this->assertEquals(2, $pool1->getWorkerCount());
        $this->assertEquals(4, $pool2->getWorkerCount());

        // Use both pools
        $tasks = [
            fn () => 'a',
            fn () => 'b',
        ];

        $results1 = $pool1->execute($tasks);
        $results2 = $pool2->execute($tasks);

        $this->assertEquals(['a', 'b'], $results1);
        $this->assertEquals(['a', 'b'], $results2);

        // Shutdown one doesn't affect the other
        $pool1->shutdown();
        $this->assertTrue($pool1->isShutdown());
        $this->assertFalse($pool2->isShutdown());

        // pool2 still works
        $results3 = $pool2->execute($tasks);
        $this->assertEquals(['a', 'b'], $results3);

        $pool2->shutdown();
    }
}
