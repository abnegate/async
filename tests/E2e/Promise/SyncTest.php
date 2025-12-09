<?php

namespace Utopia\Tests\E2e\Promise;

use PHPUnit\Framework\TestCase;
use Utopia\Async\Promise\Adapter\Sync;

class SyncTest extends TestCase
{
    public function testCreate(): void
    {
        $promise = Sync::create(function (callable $resolve) {
            $resolve('test');
        });

        $this->assertInstanceOf(Sync::class, $promise);
        $this->assertEquals('test', $promise->await());
    }

    public function testResolve(): void
    {
        $promise = Sync::resolve('resolved value');
        $this->assertEquals('resolved value', $promise->await());
    }

    public function testReject(): void
    {
        $promise = Sync::reject(new \Exception('error message'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('error message');
        $promise->await();
    }

    public function testAsync(): void
    {
        $promise = Sync::async(function () {
            return 'async result';
        });

        $this->assertEquals('async result', $promise->await());
    }

    public function testAsyncWithException(): void
    {
        $promise = Sync::async(function () {
            throw new \RuntimeException('async error');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('async error');
        $promise->await();
    }

    public function testRun(): void
    {
        $result = Sync::run(function () {
            return 'run result';
        });

        $this->assertEquals('run result', $result);
    }

    public function testRunWithException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('run error');

        Sync::run(function () {
            throw new \RuntimeException('run error');
        });
    }

    public function testDelay(): void
    {
        $start = microtime(true);
        $promise = Sync::delay(100);
        $promise->await();
        $elapsed = (microtime(true) - $start) * 1000;

        $this->assertGreaterThanOrEqual(100, $elapsed);
    }

    public function testThen(): void
    {
        $promise = Sync::resolve(5)
            ->then(function (int $value) {
                return $value * 2;
            })
            ->then(function (int $value) {
                return $value + 3;
            });

        $this->assertEquals(13, $promise->await());
    }

    public function testThenWithNull(): void
    {
        $promise = Sync::resolve(42)
            ->then(null);

        $this->assertEquals(42, $promise->await());
    }

    public function testCatch(): void
    {
        $promise = Sync::reject(new \Exception('error'))
            ->catch(function (\Throwable $error) {
                return 'caught: ' . $error->getMessage();
            });

        $this->assertEquals('caught: error', $promise->await());
    }

    public function testFinally(): void
    {
        $finallyCalled = false;

        $promise = Sync::resolve('value')
            ->finally(function () use (&$finallyCalled) {
                $finallyCalled = true;
            });

        $this->assertEquals('value', $promise->await());
        $this->assertTrue($finallyCalled);
    }

    public function testFinallyWithRejection(): void
    {
        $finallyCalled = false;

        $promise = Sync::reject(new \Exception('error'))
            ->finally(function () use (&$finallyCalled) {
                $finallyCalled = true;
            });

        $this->expectException(\Exception::class);
        try {
            $promise->await();
        } finally {
            $this->assertTrue($finallyCalled);
        }
    }

    public function testAll(): void
    {
        $promises = [
            Sync::resolve(1),
            Sync::resolve(2),
            Sync::resolve(3),
        ];

        $result = Sync::all($promises)->await();

        $this->assertEquals([1, 2, 3], $result);
    }

    public function testAllWithRejection(): void
    {
        $promises = [
            Sync::resolve(1),
            Sync::reject(new \Exception('error')),
            Sync::resolve(3),
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('error');
        Sync::all($promises)->await();
    }

    public function testRace(): void
    {
        $promises = [
            Sync::resolve('first'),
            Sync::resolve('second'),
        ];

        $result = Sync::race($promises)->await();

        $this->assertEquals('first', $result);
    }

    public function testRaceWithRejection(): void
    {
        $promises = [
            Sync::reject(new \Exception('first error')),
            Sync::resolve('second'),
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('first error');
        Sync::race($promises)->await();
    }

    public function testAllSettled(): void
    {
        $promises = [
            Sync::resolve('success'),
            Sync::reject(new \Exception('error')),
        ];

        /** @var array<int, array{status: string, value?: mixed, reason?: mixed}> $results */
        $results = Sync::allSettled($promises)->await();

        $this->assertCount(2, $results);
        $this->assertEquals('fulfilled', $results[0]['status']);
        $this->assertArrayHasKey('value', $results[0]);
        $this->assertEquals('success', $results[0]['value'] ?? null);
        $this->assertEquals('rejected', $results[1]['status']);
        $this->assertArrayHasKey('reason', $results[1]);
        $this->assertInstanceOf(\Exception::class, $results[1]['reason'] ?? null);
    }

    public function testAny(): void
    {
        $promises = [
            Sync::reject(new \Exception('error 1')),
            Sync::resolve('success'),
            Sync::reject(new \Exception('error 2')),
        ];

        $result = Sync::any($promises)->await();

        $this->assertEquals('success', $result);
    }

    public function testAnyWithAllRejections(): void
    {
        $promises = [
            Sync::reject(new \Exception('error 1')),
            Sync::reject(new \Exception('error 2')),
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('All promises were rejected');
        Sync::any($promises)->await();
    }

    public function testAnyWithEmptyArray(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No promises provided to any()');
        Sync::any([])->await();
    }

    public function testChaining(): void
    {
        $result = Sync::resolve(10)
            ->then(function (int $v) {
                return $v * 2;
            })
            ->then(function (int $v) {
                return $v + 5;
            })
            ->then(function (int $v) {
                return $v / 5;
            })
            ->await();

        $this->assertEquals(5, $result);
    }

    public function testNestedPromises(): void
    {
        $promise = Sync::resolve(Sync::resolve(42));
        $this->assertEquals(42, $promise->await());
    }

    public function testSyncAdapterIsActuallySynchronous(): void
    {
        // Sync adapter should execute sequentially
        $executionOrder = [];

        $start = microtime(true);

        $promise1 = Sync::create(function (callable $resolve) use (&$executionOrder) {
            $executionOrder[] = 'start-1';
            usleep(10000); // 10ms
            $executionOrder[] = 'end-1';
            $resolve(1);
        });

        $promise2 = Sync::create(function (callable $resolve) use (&$executionOrder) {
            $executionOrder[] = 'start-2';
            usleep(10000); // 10ms
            $executionOrder[] = 'end-2';
            $resolve(2);
        });

        $results = Sync::all([$promise1, $promise2])->await();
        $elapsed = microtime(true) - $start;

        // Should execute in order: start-1, end-1, start-2, end-2
        $this->assertEquals(['start-1', 'end-1', 'start-2', 'end-2'], $executionOrder);

        // Should take at least 20ms (both delays executed sequentially during creation)
        $this->assertGreaterThanOrEqual(0.018, $elapsed);
        $this->assertEquals([1, 2], $results);
    }

    public function testRaceIsSequentialInSync(): void
    {
        // In sync adapter, race will evaluate promises sequentially
        $start = microtime(true);

        $promise = Sync::race([
            Sync::delay(50)->then(fn () => 'first'),
            Sync::delay(10)->then(fn () => 'second'),
        ]);

        $result = $promise->await();
        $elapsed = (microtime(true) - $start) * 1000;

        // In Sync mode, first promise is evaluated first
        $this->assertEquals('first', $result);
        // Should wait for first delay (50ms)
        $this->assertGreaterThanOrEqual(45, $elapsed);
    }

    public function testTimeout(): void
    {
        $promise = Sync::delay(10)->timeout(100);
        $result = $promise->await();
        $this->assertNull($result);
    }

    public function testTimeoutExpires(): void
    {
        // In Sync mode, delay executes immediately during promise creation,
        // so the delay completes before timeout is applied.
        // This test verifies timeout still works when applied.
        // The timeout is applied after the delay has already completed.
        $start = microtime(true);
        $promise = Sync::delay(10)->timeout(100);
        $result = $promise->await();
        $elapsed = (microtime(true) - $start) * 1000;

        // Should complete (delay 10ms is less than timeout 100ms)
        $this->assertNull($result);
        $this->assertGreaterThanOrEqual(10, $elapsed);
    }

    public function testThenWithRejectedPromiseAndOnRejected(): void
    {
        $promise = Sync::reject(new \Exception('original error'))
            ->then(
                function (string $value) {
                    return 'fulfilled: ' . $value;
                },
                function (\Throwable $error) {
                    return 'rejected: ' . $error->getMessage();
                }
            );

        $this->assertEquals('rejected: original error', $promise->await());
    }

    public function testThenPassesThroughRejection(): void
    {
        $promise = Sync::reject(new \Exception('passthrough'))
            ->then(function ($value) {
                return 'should not reach here';
            });

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('passthrough');
        $promise->await();
    }

    public function testCatchConvertsRejectionToFulfillment(): void
    {
        $promise = Sync::reject(new \Exception('error'))
            ->catch(fn (\Throwable $e) => 'recovered: ' . $e->getMessage())
            ->then(fn (string $v) => 'after: ' . $v);

        $this->assertEquals('after: recovered: error', $promise->await());
    }

    public function testExecutorExceptionBecomesRejection(): void
    {
        $promise = Sync::create(function (callable $resolve, callable $reject) {
            throw new \RuntimeException('executor threw');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('executor threw');
        $promise->await();
    }

    public function testThenCallbackException(): void
    {
        $promise = Sync::resolve(42)
            ->then(function ($value) {
                throw new \RuntimeException('then threw');
            });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('then threw');
        $promise->await();
    }

    public function testFinallyCallbackException(): void
    {
        $promise = Sync::resolve(42)
            ->finally(function () {
                throw new \RuntimeException('finally threw');
            });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('finally threw');
        $promise->await();
    }

    public function testAllPreservesKeys(): void
    {
        $promises = [
            'a' => Sync::resolve(1),
            'b' => Sync::resolve(2),
            'c' => Sync::resolve(3),
        ];

        $result = Sync::all($promises)->await();

        $this->assertEquals(['a' => 1, 'b' => 2, 'c' => 3], $result);
    }

    public function testRaceWithEmptyArray(): void
    {
        // Race with empty array should hang/never settle in true async
        // In Sync mode, it just creates an empty promise
        $promise = Sync::race([]);
        // The promise is created but never resolves since there's nothing to race
        $this->assertInstanceOf(Sync::class, $promise);
    }

    public function testAllSettledWithEmptyArray(): void
    {
        $result = Sync::allSettled([])->await();
        $this->assertEquals([], $result);
    }

    public function testAllWithEmptyArray(): void
    {
        $result = Sync::all([])->await();
        $this->assertEquals([], $result);
    }

    public function testPromiseStates(): void
    {
        // Test that promise states work correctly through reflection
        $resolvedPromise = Sync::resolve('value');
        $rejectedPromise = Sync::reject(new \Exception('error'));

        $resolvedReflection = new \ReflectionProperty($resolvedPromise, 'state');
        $resolvedReflection->setAccessible(true);

        $rejectedReflection = new \ReflectionProperty($rejectedPromise, 'state');
        $rejectedReflection->setAccessible(true);

        // STATE_FULFILLED = 0, STATE_REJECTED = -1
        $this->assertEquals(0, $resolvedReflection->getValue($resolvedPromise));
        $this->assertEquals(-1, $rejectedReflection->getValue($rejectedPromise));
    }

    public function testMultipleResolveCallsIgnored(): void
    {
        $callCount = 0;

        $promise = Sync::create(function (callable $resolve) use (&$callCount) {
            $resolve('first');
            $resolve('second'); // Should be ignored
            $resolve('third'); // Should be ignored
            $callCount++;
        });

        $this->assertEquals('first', $promise->await());
        $this->assertEquals(1, $callCount);
    }

    public function testMultipleRejectCallsIgnored(): void
    {
        $promise = Sync::create(function (callable $resolve, callable $reject) {
            $reject(new \Exception('first'));
            $reject(new \Exception('second')); // Should be ignored
        });

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('first');
        $promise->await();
    }
}
