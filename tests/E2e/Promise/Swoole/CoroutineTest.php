<?php

namespace Utopia\Tests\E2e\Promise\Swoole;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine as SwooleCoroutine;
use Utopia\Async\Exception\Timeout;
use Utopia\Async\Promise\Adapter\Swoole\Coroutine;

class CoroutineTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Thread extension is not available');
        }
    }

    public function testCreate(): void
    {
        SwooleCoroutine\run(function () {
            $promise = Coroutine::create(function (callable $resolve) {
                $resolve('test');
            });

            $this->assertInstanceOf(Coroutine::class, $promise);
            $this->assertEquals('test', $promise->await());
        });
    }

    public function testResolve(): void
    {
        SwooleCoroutine\run(function () {
            $promise = Coroutine::resolve('resolved value');
            $this->assertEquals('resolved value', $promise->await());
        });
    }

    public function testReject(): void
    {
        SwooleCoroutine\run(function () {
            $promise = Coroutine::reject(new \Exception('error message'));

            try {
                $promise->await();
                $this->fail('Expected exception was not thrown');
            } catch (\Exception $e) {
                $this->assertEquals('error message', $e->getMessage());
            }
        });
    }

    public function testAsync(): void
    {
        SwooleCoroutine\run(function () {
            $promise = Coroutine::async(function () {
                return 'async result';
            });

            $this->assertEquals('async result', $promise->await());
        });
    }

    public function testAsyncWithException(): void
    {
        SwooleCoroutine\run(function () {
            $promise = Coroutine::async(function () {
                throw new \RuntimeException('async error');
            });

            try {
                $promise->await();
                $this->fail('Expected exception was not thrown');
            } catch (\RuntimeException $e) {
                $this->assertEquals('async error', $e->getMessage());
            }
        });
    }

    public function testRun(): void
    {
        SwooleCoroutine\run(function () {
            $result = Coroutine::run(function () {
                return 'run result';
            });

            $this->assertEquals('run result', $result);
        });
    }

    public function testDelay(): void
    {
        SwooleCoroutine\run(function () {
            $start = microtime(true);
            $promise = Coroutine::delay(100);
            $promise->await();
            $elapsed = (microtime(true) - $start) * 1000;

            $this->assertGreaterThanOrEqual(95, $elapsed);
            $this->assertLessThan(150, $elapsed);
        });
    }

    public function testThen(): void
    {
        SwooleCoroutine\run(function () {
            $promise = Coroutine::resolve(5)
                ->then(function (int $value): int {
                    return $value * 2;
                })
                ->then(function (int $value): int {
                    return $value + 3;
                });

            $this->assertEquals(13, $promise->await());
        });
    }

    public function testCatch(): void
    {
        SwooleCoroutine\run(function () {
            $promise = Coroutine::reject(new \Exception('error'))
                ->catch(function (\Throwable $error) {
                    return 'caught: ' . $error->getMessage();
                });

            $this->assertEquals('caught: error', $promise->await());
        });
    }

    public function testFinally(): void
    {
        SwooleCoroutine\run(function () {
            $finallyCalled = false;

            $promise = Coroutine::resolve('value')
                ->finally(function () use (&$finallyCalled) {
                    $finallyCalled = true;
                });

            $this->assertEquals('value', $promise->await());
            $this->assertTrue($finallyCalled);
        });
    }

    public function testAll(): void
    {
        SwooleCoroutine\run(function () {
            $promises = [
                Coroutine::resolve(1),
                Coroutine::resolve(2),
                Coroutine::resolve(3),
            ];

            $result = Coroutine::all($promises)->await();

            $this->assertEquals([1, 2, 3], $result);
        });
    }

    public function testAllWithRejection(): void
    {
        SwooleCoroutine\run(function () {
            $promises = [
                Coroutine::resolve(1),
                Coroutine::reject(new \Exception('error')),
                Coroutine::resolve(3),
            ];

            try {
                Coroutine::all($promises)->await();
                $this->fail('Expected exception was not thrown');
            } catch (\Exception $e) {
                $this->assertEquals('error', $e->getMessage());
            }
        });
    }

    public function testRace(): void
    {
        SwooleCoroutine\run(function () {
            $promises = [
                Coroutine::delay(50)->then(fn () => 'slow'),
                Coroutine::resolve('fast'),
            ];

            $result = Coroutine::race($promises)->await();

            $this->assertEquals('fast', $result);
        });
    }

    public function testAllSettled(): void
    {
        SwooleCoroutine\run(function () {
            $promises = [
                Coroutine::resolve('success'),
                Coroutine::reject(new \Exception('error')),
            ];

            $results = Coroutine::allSettled($promises)->await();

            $this->assertIsArray($results);
            $this->assertCount(2, $results);
            $this->assertIsArray($results[0]);
            $this->assertIsArray($results[1]);
            $this->assertEquals('fulfilled', $results[0]['status']);
            $this->assertEquals('success', $results[0]['value']);
            $this->assertEquals('rejected', $results[1]['status']);
            $this->assertInstanceOf(\Exception::class, $results[1]['reason']);
        });
    }

    public function testAny(): void
    {
        SwooleCoroutine\run(function () {
            $promises = [
                Coroutine::reject(new \Exception('error 1')),
                Coroutine::resolve('success'),
                Coroutine::reject(new \Exception('error 2')),
            ];

            $result = Coroutine::any($promises)->await();

            $this->assertEquals('success', $result);
        });
    }

    public function testAnyWithAllRejections(): void
    {
        SwooleCoroutine\run(function () {
            $promises = [
                Coroutine::reject(new \Exception('error 1')),
                Coroutine::reject(new \Exception('error 2')),
            ];

            try {
                Coroutine::any($promises)->await();
                $this->fail('Expected exception was not thrown');
            } catch (\Exception $e) {
                $this->assertEquals('All promises were rejected', $e->getMessage());
            }
        });
    }

    public function testAnyWithEmptyArray(): void
    {
        SwooleCoroutine\run(function () {
            try {
                Coroutine::any([])->await();
                $this->fail('Expected exception was not thrown');
            } catch (\Exception $e) {
                $this->assertEquals('No promises provided to any()', $e->getMessage());
            }
        });
    }

    public function testConcurrentExecution(): void
    {
        SwooleCoroutine\run(function () {
            $start = microtime(true);

            $promises = [
                Coroutine::async(function () {
                    SwooleCoroutine::sleep(0.1);
                    return 'result1';
                }),
                Coroutine::async(function () {
                    SwooleCoroutine::sleep(0.1);
                    return 'result2';
                }),
                Coroutine::async(function () {
                    SwooleCoroutine::sleep(0.1);
                    return 'result3';
                }),
            ];

            $results = Coroutine::all($promises)->await();
            $elapsed = microtime(true) - $start;

            $this->assertEquals(['result1', 'result2', 'result3'], $results);
            // Should take ~0.1s (concurrent) not 0.3s (sequential)
            $this->assertLessThan(0.2, $elapsed);
            // Must be at least 0.1s (the sleep time)
            $this->assertGreaterThanOrEqual(0.095, $elapsed);
        });
    }

    public function testActualConcurrency(): void
    {
        SwooleCoroutine\run(function () {
            $executionOrder = [];
            $start = microtime(true);

            $promises = [
                Coroutine::async(function () use (&$executionOrder) {
                    $executionOrder[] = 'start-1';
                    SwooleCoroutine::sleep(0.05);
                    $executionOrder[] = 'end-1';
                    return 1;
                }),
                Coroutine::async(function () use (&$executionOrder) {
                    $executionOrder[] = 'start-2';
                    SwooleCoroutine::sleep(0.05);
                    $executionOrder[] = 'end-2';
                    return 2;
                }),
                Coroutine::async(function () use (&$executionOrder) {
                    $executionOrder[] = 'start-3';
                    SwooleCoroutine::sleep(0.05);
                    $executionOrder[] = 'end-3';
                    return 3;
                }),
            ];

            $results = Coroutine::all($promises)->await();
            $elapsed = microtime(true) - $start;

            // All promises should start before any finish (concurrent execution)
            $this->assertContains('start-1', array_slice($executionOrder, 0, 3));
            $this->assertContains('start-2', array_slice($executionOrder, 0, 3));
            $this->assertContains('start-3', array_slice($executionOrder, 0, 3));

            // Timing proves concurrency
            $this->assertLessThan(0.1, $elapsed, 'Concurrent execution should be under 100ms');
            $this->assertEquals([1, 2, 3], $results);
        });
    }

    public function testRaceActuallyRaces(): void
    {
        SwooleCoroutine\run(function () {
            $start = microtime(true);

            $promise = Coroutine::race([
                Coroutine::delay(100)->then(fn () => 'slow'),
                Coroutine::delay(10)->then(fn () => 'fast'),
                Coroutine::delay(50)->then(fn () => 'medium'),
            ]);

            $result = $promise->await();
            $elapsed = (microtime(true) - $start) * 1000;

            // Should return the fastest one
            $this->assertEquals('fast', $result);
            // Should complete in ~10ms, not wait for all
            $this->assertLessThan(30, $elapsed);
            $this->assertGreaterThanOrEqual(8, $elapsed);
        });
    }

    public function testNestedPromises(): void
    {
        SwooleCoroutine\run(function () {
            $promise = Coroutine::resolve(Coroutine::resolve(42));
            $this->assertEquals(42, $promise->await());
        });
    }

    public function testTimeout(): void
    {
        SwooleCoroutine\run(function () {
            $promise = Coroutine::delay(200)->timeout(100);

            try {
                $promise->await();
                $this->fail('Expected exception was not thrown');
            } catch (Timeout $e) {
                $this->assertStringContainsString('timed out', $e->getMessage());
            }
        });
    }

    public function testTimeoutSuccess(): void
    {
        SwooleCoroutine\run(function () {
            $promise = Coroutine::delay(50)
                ->then(fn () => 'success')
                ->timeout(200);

            $this->assertEquals('success', $promise->await());
        });
    }
}
