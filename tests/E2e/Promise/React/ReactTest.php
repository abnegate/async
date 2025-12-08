<?php

namespace Utopia\Tests\E2e\Promise\React;

use PHPUnit\Framework\TestCase;
use Utopia\Async\Promise\Adapter\React;

class ReactTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\class_exists(\React\EventLoop\Loop::class)) {
            $this->markTestSkipped('react/event-loop is not available');
        }
    }

    public function testCreate(): void
    {
        $promise = React::create(function (callable $resolve) {
            $resolve('test');
        });

        $this->assertInstanceOf(React::class, $promise);
        $this->assertEquals('test', $promise->await());
    }

    public function testResolve(): void
    {
        $promise = React::resolve('resolved value');
        $this->assertEquals('resolved value', $promise->await());
    }

    public function testReject(): void
    {
        $promise = React::reject(new \Exception('error message'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('error message');
        $promise->await();
    }

    public function testAsync(): void
    {
        $promise = React::async(function () {
            return 'async result';
        });

        $this->assertEquals('async result', $promise->await());
    }

    public function testAsyncWithException(): void
    {
        $promise = React::async(function () {
            throw new \RuntimeException('async error');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('async error');
        $promise->await();
    }

    public function testRun(): void
    {
        $result = React::run(function () {
            return 'run result';
        });

        $this->assertEquals('run result', $result);
    }

    public function testRunWithException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('run error');

        React::run(function () {
            throw new \RuntimeException('run error');
        });
    }

    public function testDelay(): void
    {
        $start = microtime(true);
        $promise = React::delay(50);
        $promise->await();
        $elapsed = (microtime(true) - $start) * 1000;

        $this->assertGreaterThanOrEqual(45, $elapsed);
    }

    public function testThen(): void
    {
        $promise = React::resolve(5)
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
        $promise = React::resolve(42)
            ->then(null);

        $this->assertEquals(42, $promise->await());
    }

    public function testCatch(): void
    {
        $promise = React::reject(new \Exception('error'))
            ->catch(function ($error) {
                return 'caught: ' . $error->getMessage();
            });

        $this->assertEquals('caught: error', $promise->await());
    }

    public function testFinally(): void
    {
        $finallyCalled = false;

        $promise = React::resolve('value')
            ->finally(function () use (&$finallyCalled) {
                $finallyCalled = true;
            });

        $this->assertEquals('value', $promise->await());
        $this->assertTrue($finallyCalled);
    }

    public function testFinallyWithRejection(): void
    {
        $finallyCalled = false;

        $promise = React::reject(new \Exception('error'))
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
            React::resolve(1),
            React::resolve(2),
            React::resolve(3),
        ];

        $result = React::all($promises)->await();

        $this->assertEquals([1, 2, 3], $result);
    }

    public function testAllWithRejection(): void
    {
        $promises = [
            React::resolve(1),
            React::reject(new \Exception('error')),
            React::resolve(3),
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('error');
        React::all($promises)->await();
    }

    public function testRace(): void
    {
        $promises = [
            React::resolve('first'),
            React::resolve('second'),
        ];

        $result = React::race($promises)->await();

        $this->assertEquals('first', $result);
    }

    public function testRaceWithRejection(): void
    {
        $promises = [
            React::reject(new \Exception('first error')),
            React::resolve('second'),
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('first error');
        React::race($promises)->await();
    }

    public function testAllSettled(): void
    {
        $promises = [
            React::resolve('success'),
            React::reject(new \Exception('error')),
        ];

        $results = React::allSettled($promises)->await();

        $this->assertCount(2, $results);
        $this->assertEquals('fulfilled', $results[0]['status']);
        $this->assertEquals('success', $results[0]['value']);
        $this->assertEquals('rejected', $results[1]['status']);
        $this->assertInstanceOf(\Exception::class, $results[1]['reason']);
    }

    public function testAny(): void
    {
        $promises = [
            React::reject(new \Exception('error 1')),
            React::resolve('success'),
            React::reject(new \Exception('error 2')),
        ];

        $result = React::any($promises)->await();

        $this->assertEquals('success', $result);
    }

    public function testAnyWithAllRejections(): void
    {
        $promises = [
            React::reject(new \Exception('error 1')),
            React::reject(new \Exception('error 2')),
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('All promises were rejected');
        React::any($promises)->await();
    }

    public function testAnyWithEmptyArray(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No promises provided to any()');
        React::any([])->await();
    }

    public function testChaining(): void
    {
        $result = React::resolve(10)
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
        $promise = React::resolve(React::resolve(42));
        $this->assertEquals(42, $promise->await());
    }

    public function testAllPreservesKeys(): void
    {
        $promises = [
            'a' => React::resolve(1),
            'b' => React::resolve(2),
            'c' => React::resolve(3),
        ];

        $result = React::all($promises)->await();

        $this->assertEquals(['a' => 1, 'b' => 2, 'c' => 3], $result);
    }

    public function testAllSettledWithEmptyArray(): void
    {
        $result = React::allSettled([])->await();
        $this->assertEquals([], $result);
    }

    public function testAllWithEmptyArray(): void
    {
        $result = React::all([])->await();
        $this->assertEquals([], $result);
    }

    public function testExecutorExceptionBecomesRejection(): void
    {
        $promise = React::create(function ($resolve, $reject) {
            throw new \RuntimeException('executor threw');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('executor threw');
        $promise->await();
    }

    public function testThenCallbackException(): void
    {
        $promise = React::resolve(42)
            ->then(function ($value) {
                throw new \RuntimeException('then threw');
            });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('then threw');
        $promise->await();
    }

    public function testCatchConvertsRejectionToFulfillment(): void
    {
        $promise = React::reject(new \Exception('error'))
            ->catch(fn ($e) => 'recovered: ' . $e->getMessage())
            ->then(fn ($v) => 'after: ' . $v);

        $this->assertEquals('after: recovered: error', $promise->await());
    }

    public function testMultipleResolveCallsIgnored(): void
    {
        $callCount = 0;

        $promise = React::create(function ($resolve) use (&$callCount) {
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
        $promise = React::create(function ($resolve, $reject) {
            $reject(new \Exception('first'));
            $reject(new \Exception('second'));
        });

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('first');
        $promise->await();
    }
}
