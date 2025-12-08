<?php

namespace Utopia\Tests\E2e\Promise\Amp;

use PHPUnit\Framework\TestCase;
use Utopia\Async\Promise\Adapter\Amp;

class AmpTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\function_exists('Amp\async')) {
            $this->markTestSkipped('amphp/amp is not available');
        }
    }

    public function testCreate(): void
    {
        $promise = Amp::create(function (callable $resolve) {
            $resolve('test');
        });

        $this->assertInstanceOf(Amp::class, $promise);
        $this->assertEquals('test', $promise->await());
    }

    public function testResolve(): void
    {
        $promise = Amp::resolve('resolved value');
        $this->assertEquals('resolved value', $promise->await());
    }

    public function testReject(): void
    {
        $promise = Amp::reject(new \Exception('error message'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('error message');
        $promise->await();
    }

    public function testAsync(): void
    {
        $promise = Amp::async(function () {
            return 'async result';
        });

        $this->assertEquals('async result', $promise->await());
    }

    public function testAsyncWithException(): void
    {
        $promise = Amp::async(function () {
            throw new \RuntimeException('async error');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('async error');
        $promise->await();
    }

    public function testRun(): void
    {
        $result = Amp::run(function () {
            return 'run result';
        });

        $this->assertEquals('run result', $result);
    }

    public function testRunWithException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('run error');

        Amp::run(function () {
            throw new \RuntimeException('run error');
        });
    }

    public function testDelay(): void
    {
        $start = microtime(true);
        $promise = Amp::delay(50);
        $promise->await();
        $elapsed = (microtime(true) - $start) * 1000;

        $this->assertGreaterThanOrEqual(45, $elapsed);
    }

    public function testThen(): void
    {
        $promise = Amp::resolve(5)
            ->then(function ($value) {
                return $value * 2;
            })
            ->then(function ($value) {
                return $value + 3;
            });

        $this->assertEquals(13, $promise->await());
    }

    public function testThenWithNull(): void
    {
        $promise = Amp::resolve(42)
            ->then(null);

        $this->assertEquals(42, $promise->await());
    }

    public function testCatch(): void
    {
        $promise = Amp::reject(new \Exception('error'))
            ->catch(function ($error) {
                return 'caught: ' . $error->getMessage();
            });

        $this->assertEquals('caught: error', $promise->await());
    }

    public function testFinally(): void
    {
        $finallyCalled = false;

        $promise = Amp::resolve('value')
            ->finally(function () use (&$finallyCalled) {
                $finallyCalled = true;
            });

        $this->assertEquals('value', $promise->await());
        $this->assertTrue($finallyCalled);
    }

    public function testFinallyWithRejection(): void
    {
        $finallyCalled = false;

        $promise = Amp::reject(new \Exception('error'))
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
            Amp::resolve(1),
            Amp::resolve(2),
            Amp::resolve(3),
        ];

        $result = Amp::all($promises)->await();

        $this->assertEquals([1, 2, 3], $result);
    }

    public function testAllWithRejection(): void
    {
        $promises = [
            Amp::resolve(1),
            Amp::reject(new \Exception('error')),
            Amp::resolve(3),
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('error');
        Amp::all($promises)->await();
    }

    public function testRace(): void
    {
        $promises = [
            Amp::resolve('first'),
            Amp::resolve('second'),
        ];

        $result = Amp::race($promises)->await();

        $this->assertEquals('first', $result);
    }

    public function testRaceWithRejection(): void
    {
        $promises = [
            Amp::reject(new \Exception('first error')),
            Amp::resolve('second'),
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('first error');
        Amp::race($promises)->await();
    }

    public function testAllSettled(): void
    {
        $promises = [
            Amp::resolve('success'),
            Amp::reject(new \Exception('error')),
        ];

        $results = Amp::allSettled($promises)->await();

        $this->assertCount(2, $results);
        $this->assertEquals('fulfilled', $results[0]['status']);
        $this->assertEquals('success', $results[0]['value']);
        $this->assertEquals('rejected', $results[1]['status']);
        $this->assertInstanceOf(\Exception::class, $results[1]['reason']);
    }

    public function testAny(): void
    {
        $promises = [
            Amp::reject(new \Exception('error 1')),
            Amp::resolve('success'),
            Amp::reject(new \Exception('error 2')),
        ];

        $result = Amp::any($promises)->await();

        $this->assertEquals('success', $result);
    }

    public function testAnyWithAllRejections(): void
    {
        $promises = [
            Amp::reject(new \Exception('error 1')),
            Amp::reject(new \Exception('error 2')),
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('All promises were rejected');
        Amp::any($promises)->await();
    }

    public function testAnyWithEmptyArray(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No promises provided to any()');
        Amp::any([])->await();
    }

    public function testChaining(): void
    {
        $result = Amp::resolve(10)
            ->then(function ($v) {
                return $v * 2;
            })
            ->then(function ($v) {
                return $v + 5;
            })
            ->then(function ($v) {
                return $v / 5;
            })
            ->await();

        $this->assertEquals(5, $result);
    }

    public function testNestedPromises(): void
    {
        $promise = Amp::resolve(Amp::resolve(42));
        $this->assertEquals(42, $promise->await());
    }

    public function testAllPreservesKeys(): void
    {
        $promises = [
            'a' => Amp::resolve(1),
            'b' => Amp::resolve(2),
            'c' => Amp::resolve(3),
        ];

        $result = Amp::all($promises)->await();

        $this->assertEquals(['a' => 1, 'b' => 2, 'c' => 3], $result);
    }

    public function testAllSettledWithEmptyArray(): void
    {
        $result = Amp::allSettled([])->await();
        $this->assertEquals([], $result);
    }

    public function testAllWithEmptyArray(): void
    {
        $result = Amp::all([])->await();
        $this->assertEquals([], $result);
    }

    public function testExecutorExceptionBecomesRejection(): void
    {
        $promise = Amp::create(function ($resolve, $reject) {
            throw new \RuntimeException('executor threw');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('executor threw');
        $promise->await();
    }

    public function testThenCallbackException(): void
    {
        $promise = Amp::resolve(42)
            ->then(function ($value) {
                throw new \RuntimeException('then threw');
            });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('then threw');
        $promise->await();
    }

    public function testCatchConvertsRejectionToFulfillment(): void
    {
        $promise = Amp::reject(new \Exception('error'))
            ->catch(fn ($e) => 'recovered: ' . $e->getMessage())
            ->then(fn ($v) => 'after: ' . $v);

        $this->assertEquals('after: recovered: error', $promise->await());
    }

    public function testMultipleResolveCallsIgnored(): void
    {
        $callCount = 0;

        $promise = Amp::create(function ($resolve) use (&$callCount) {
            $resolve('first');
            $resolve('second');
            $resolve('third');
            $callCount++;
        });

        $this->assertEquals('first', $promise->await());
        $this->assertEquals(1, $callCount);
    }

    public function testMultipleRejectCallsIgnored(): void
    {
        $promise = Amp::create(function ($resolve, $reject) {
            $reject(new \Exception('first'));
            $reject(new \Exception('second'));
        });

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('first');
        $promise->await();
    }
}
