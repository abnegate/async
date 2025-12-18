<?php

namespace Utopia\Tests\E2e\Parallel\Swoole;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Swoole\Thread as SwooleThread;
use Utopia\Async\Parallel\Adapter\Swoole\Thread;

#[Group('swoole-thread')]
class ThreadTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not available');
        }

        if (!\class_exists(SwooleThread::class)) {
            $this->markTestSkipped('Swoole Thread support is not available. Requires Swoole >= 6.0 with thread support.');
        }

        // Ensure fresh pool state for each test
        Thread::shutdown();
    }

    public static function tearDownAfterClass(): void
    {
        // Ensure the static pool is shut down after all tests complete
        Thread::shutdown();
    }

    public function testRun(): void
    {
        $result = Thread::run(function () {
            return 'test result';
        });

        $this->assertEquals('test result', $result);
    }

    public function testRunWithArguments(): void
    {
        $result = Thread::run(function (int $a, int $b) {
            return $a + $b;
        }, 5, 3);

        $this->assertEquals(8, $result);
    }

    public function testRunWithException(): void
    {
        $this->expectException(\Exception::class);

        Thread::run(function () {
            throw new \RuntimeException('thread error');
        });
    }

    public function testAll(): void
    {
        $tasks = [
            fn () => 1,
            fn () => 2,
            fn () => 3,
        ];

        $results = Thread::all($tasks);

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

        $results = Thread::all($tasks);

        $this->assertEquals([0, 2, 4, 6, 8], $results);
    }

    public function testMap(): void
    {
        $items = [1, 2, 3, 4, 5];

        $results = Thread::map($items, function (int $item) {
            return $item * 2;
        });

        $this->assertEquals([0 => 2, 1 => 4, 2 => 6, 3 => 8, 4 => 10], $results);
    }

    public function testMapWithEmptyArray(): void
    {
        $results = Thread::map([], function (int $item) {
            return $item * 2;
        });

        $this->assertEquals([], $results);
    }

    public function testMapWithCustomWorkerCount(): void
    {
        $items = range(1, 10);

        $results = Thread::map($items, function (int $item) {
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

        $results = Thread::map($items, function (string $item, int $index) {
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

        $results = Thread::pool($tasks, 3);

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

        $results = Thread::pool($tasks, 1);

        $this->assertEquals([
            0 => 'first',
            1 => 'second',
            2 => 'third',
        ], $results);
    }

    public function testPoolMaintainsOrder(): void
    {
        // Create separate closures for each task to avoid Opis\Closure serialization issues
        $tasks = [];
        for ($i = 0; $i < 5; $i++) {
            $taskIndex = $i;
            $tasks[] = function () use ($taskIndex) {
                \usleep(\rand(1000, 5000));
                return "task_{$taskIndex}";
            };
        }

        $results = Thread::pool($tasks, 2);

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
        // Create separate task closures to avoid Opis\Closure serialization issues
        // Use longer task duration so parallel benefit outweighs thread overhead
        $tasks = [];
        for ($i = 0; $i < 4; $i++) {
            $tasks[] = function () {
                \usleep(150000); // 150ms per task
                return true;
            };
        }

        // Measure parallel execution
        $start = microtime(true);
        Thread::all($tasks);
        $parallelTime = microtime(true) - $start;

        // With 4 tasks of 150ms each, sequential would be ~600ms
        // Parallel should complete in ~150ms + overhead, must be under 500ms
        $this->assertLessThan(0.5, $parallelTime, 'Parallel execution should be less than 500ms (sequential would be 600ms)');
        $this->assertGreaterThanOrEqual(0.14, $parallelTime, 'Should take at least the task duration');
    }

    public function testTrueParallelismNotSequential(): void
    {
        // Create tasks that run for a specific duration
        // Use longer duration so parallel benefit outweighs thread overhead
        $taskDuration = 150; // 150ms per task
        $taskCount = 4;

        $tasks = [];
        for ($i = 0; $i < $taskCount; $i++) {
            $taskIndex = $i;
            $tasks[] = function () use ($taskDuration, $taskIndex) {
                $start = microtime(true);
                // Busy work to ensure it's CPU-bound
                while ((microtime(true) - $start) * 1000 < $taskDuration) {
                    // CPU-intensive work
                    $x = 0;
                    for ($j = 0; $j < 10000; $j++) {
                        $x += $j;
                    }
                }
                return $taskIndex;
            };
        }

        $start = microtime(true);
        $results = Thread::all($tasks);
        $elapsed = (microtime(true) - $start) * 1000;

        // Verify results
        $this->assertEquals([0, 1, 2, 3], $results);

        // With 4 tasks of 150ms each, sequential would be ~600ms
        // Parallel should complete in ~150ms + overhead, must be under 500ms
        $this->assertLessThan(500, $elapsed, 'Parallel execution should be less than 500ms (sequential would be 600ms)');
    }

    public function testMapActuallyDistributesWork(): void
    {
        $items = range(1, 20);

        $start = microtime(true);
        $results = Thread::map($items, function (int $item) {
            // Simulate some work - use longer duration to overcome thread overhead
            usleep(30000); // 30ms per item
            return $item * 2;
        }, 4); // Use 4 workers
        $elapsed = microtime(true) - $start;

        // 20 items at 30ms each = 600ms sequential
        // With 4 workers: ~150ms (20/4 * 30ms) + overhead
        // Must complete in under 500ms to prove parallelism
        $this->assertLessThan(0.5, $elapsed, 'Map should distribute work (sequential would be 600ms)');
        $this->assertCount(20, $results);
    }

    public function testMapDistributesWorkAcrossWorkers(): void
    {
        $items = range(1, 100);
        $cpuCount = function_exists('swoole_cpu_num') ? swoole_cpu_num() : 4;

        $start = microtime(true);
        $results = Thread::map($items, function (int $item) {
            return $item * 2;
        }, $cpuCount);
        $elapsed = microtime(true) - $start;

        $this->assertCount(100, $results);
        // Should be reasonably fast even with 100 items
        $this->assertLessThan(2.0, $elapsed);
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
        $results = Thread::all($tasks);
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
        $results = Thread::all($tasks);
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
        $results = Thread::all($tasks);
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
        // Run 50 tasks with moderate CPU work each
        $tasks = [];
        for ($i = 0; $i < 50; $i++) {
            $tasks[] = function () use ($i) {
                // Compute sum of squares up to 5000
                $sum = 0;
                for ($j = 1; $j <= 5000; $j++) {
                    $sum += $j * $j;
                }
                return ['index' => $i, 'sum' => $sum];
            };
        }

        $start = microtime(true);
        $results = Thread::all($tasks);
        $parallelTime = microtime(true) - $start;

        // Verify all 50 tasks completed with correct results
        $this->assertCount(50, $results);
        $expectedSum = 41679167500; // Sum of squares from 1 to 5000

        foreach ($results as $idx => $result) {
            $this->assertIsArray($result);
            /** @var array{index: int, sum: int} $result */
            $this->assertEquals($idx, $result['index']);
            $this->assertEquals($expectedSum, $result['sum']);
        }

        // With 50 tasks and pool workers, should complete reasonably fast
        $this->assertLessThan(5.0, $parallelTime, 'High volume tasks should complete within 5 seconds');
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

        $results = Thread::all($tasks);

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
        $intResult = Thread::run(fn () => 42);
        $this->assertIsInt($intResult);
        $this->assertEquals(42, $intResult);

        // Test string return
        $strResult = Thread::run(fn () => 'hello');
        $this->assertIsString($strResult);
        $this->assertEquals('hello', $strResult);

        // Test array return
        $arrResult = Thread::run(fn () => [1, 2, 3]);
        $this->assertIsArray($arrResult);
        $this->assertEquals([1, 2, 3], $arrResult);

        // Test object return
        $obj = new \stdClass();
        $obj->value = 'test';
        $objResult = Thread::run(fn () => $obj);
        $this->assertInstanceOf(\stdClass::class, $objResult);
        $this->assertEquals('test', $objResult->value);
    }

    public function testDefaultPoolPersistence(): void
    {
        // First batch of tasks
        $tasks1 = [
            fn () => 'task1',
            fn () => 'task2',
            fn () => 'task3',
        ];

        $results1 = Thread::all($tasks1);
        $this->assertEquals(['task1', 'task2', 'task3'], $results1);

        // Second batch - should reuse the same pool
        $tasks2 = [
            fn () => 'task4',
            fn () => 'task5',
        ];

        $results2 = Thread::all($tasks2);
        $this->assertEquals(['task4', 'task5'], $results2);

        // Clean up
        Thread::shutdown();
    }

    public function testPoolShutdown(): void
    {
        // Create some tasks
        $tasks = [fn () => 'test'];

        // Execute to create default pool
        Thread::all($tasks);

        // Shutdown pool
        Thread::shutdown();

        // Execute again - should create new pool
        $results = Thread::all($tasks);
        $this->assertEquals(['test'], $results);

        // Clean up
        Thread::shutdown();
    }

}
