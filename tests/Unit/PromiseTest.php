<?php

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Async\Promise;
use Utopia\Async\Promise\Adapter\Swoole\Coroutine;
use Utopia\Async\Promise\Adapter\Sync;

class PromiseTest extends TestCase
{
    public function testSetAdapter(): void
    {
        Promise::setAdapter(Sync::class);

        // Just verify it doesn't throw
        $this->expectNotToPerformAssertions();
    }

    public function testSetAdapterWithInvalidClass(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Adapter must be a valid promise adapter class');

        Promise::setAdapter(\stdClass::class);
    }

    public function testAsync(): void
    {
        // Test via run() which works correctly
        Promise::setAdapter(Sync::class);

        $result = Promise::run(function () {
            return 'async result';
        });

        $this->assertEquals('async result', $result);
    }

    public function testRun(): void
    {
        Promise::setAdapter(Sync::class);

        $result = Promise::run(function () {
            return 'run result';
        });

        $this->assertEquals('run result', $result);
    }

    public function testDelay(): void
    {
        // Delay tested in adapter tests
        $this->expectNotToPerformAssertions();
    }

    public function testAll(): void
    {
        // Collection methods tested in adapter tests
        $this->expectNotToPerformAssertions();
    }

    public function testRace(): void
    {
        // Collection methods tested in adapter tests
        $this->expectNotToPerformAssertions();
    }

    public function testAllSettled(): void
    {
        // Collection methods tested in adapter tests
        $this->expectNotToPerformAssertions();
    }

    public function testAny(): void
    {
        // Collection methods tested in adapter tests
        $this->expectNotToPerformAssertions();
    }

    public function testDefaultAdapterSelectionWithSwoole(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not available');
        }

        // Force adapter selection
        Promise::setAdapter(Coroutine::class);

        // Verify through running a promise
        $result = Promise::run(function () {
            return 'test';
        });

        $this->assertEquals('test', $result);
    }

    public function testDefaultAdapterSelectionWithoutSwoole(): void
    {
        if (extension_loaded('swoole')) {
            $this->markTestSkipped('Test requires Swoole to be disabled');
        }

        // Without Swoole, the facade should use Sync adapter
        $result = Promise::run(function () {
            return 'test';
        });

        $this->assertEquals('test', $result);
    }

    public function testAsyncReturnsAdapter(): void
    {
        Promise::setAdapter(Sync::class);

        $promise = Promise::async(function () {
            return 42;
        });

        $this->assertInstanceOf(Sync::class, $promise);
        $this->assertEquals(42, $promise->await());
    }

    public function testDelayViaAdapter(): void
    {
        // Test delay through the Sync adapter directly
        $start = microtime(true);
        $promise = Sync::delay(50);
        $promise->await();
        $elapsed = (microtime(true) - $start) * 1000;

        $this->assertGreaterThanOrEqual(50, $elapsed);
    }

    public function testAllViaAdapter(): void
    {
        // Test all through the Sync adapter directly
        $promises = [
            Sync::resolve(1),
            Sync::resolve(2),
            Sync::resolve(3),
        ];

        $result = Sync::all($promises)->await();

        $this->assertEquals([1, 2, 3], $result);
    }

    public function testRaceViaAdapter(): void
    {
        // Test race through the Sync adapter directly
        $promises = [
            Sync::resolve('first'),
            Sync::resolve('second'),
        ];

        $result = Sync::race($promises)->await();

        $this->assertEquals('first', $result);
    }

    public function testAllSettledViaAdapter(): void
    {
        // Test allSettled through the Sync adapter directly
        $promises = [
            Sync::resolve('success'),
            Sync::reject(new \Exception('error')),
        ];

        $results = Sync::allSettled($promises)->await();

        $this->assertIsArray($results);
        $this->assertCount(2, $results);
        $this->assertIsArray($results[0]);
        $this->assertIsArray($results[1]);
        $this->assertEquals('fulfilled', $results[0]['status']);
        $this->assertEquals('rejected', $results[1]['status']);
    }

    public function testAnyViaAdapter(): void
    {
        // Test any through the Sync adapter directly
        $promises = [
            Sync::reject(new \Exception('error')),
            Sync::resolve('success'),
        ];

        $result = Sync::any($promises)->await();

        $this->assertEquals('success', $result);
    }

    public function testAsyncViaAdapter(): void
    {
        // Test async through the Sync adapter directly
        $promise = Sync::async(function () {
            return 'async result';
        });

        $this->assertEquals('async result', $promise->await());
    }

    public function testResolveWithScalarValue(): void
    {
        $promise = Promise::resolve(42);
        $this->assertEquals(42, $promise->await());
    }

    public function testResolveWithString(): void
    {
        $promise = Promise::resolve('hello world');
        $this->assertEquals('hello world', $promise->await());
    }

    public function testResolveWithNull(): void
    {
        $promise = Promise::resolve(null);
        $this->assertNull($promise->await());
    }

    public function testResolveWithBool(): void
    {
        $this->assertTrue(Promise::resolve(true)->await());
        $this->assertFalse(Promise::resolve(false)->await());
    }

    public function testResolveWithArray(): void
    {
        $data = ['key' => 'value', 'nested' => ['a', 'b', 'c']];
        $promise = Promise::resolve($data);
        $this->assertEquals($data, $promise->await());
    }

    public function testResolveWithObject(): void
    {
        $obj = new \stdClass();
        $obj->name = 'test';
        $obj->value = 123;

        $promise = Promise::resolve($obj);
        $result = $promise->await();

        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertEquals('test', $result->name);
        $this->assertEquals(123, $result->value);
    }

    public function testResolveWithFloat(): void
    {
        $promise = Promise::resolve(3.14159);
        $this->assertEqualsWithDelta(3.14159, $promise->await(), 0.00001);
    }

    public function testResolveWithEmptyArray(): void
    {
        $promise = Promise::resolve([]);
        $this->assertEquals([], $promise->await());
    }

    public function testResolveWithEmptyString(): void
    {
        $promise = Promise::resolve('');
        $this->assertEquals('', $promise->await());
    }

    public function testResolveWithZero(): void
    {
        $this->assertEquals(0, Promise::resolve(0)->await());
        $this->assertEquals(0.0, Promise::resolve(0.0)->await());
    }

    public function testRejectWithException(): void
    {
        $exception = new \Exception('test error');
        $promise = Promise::reject($exception);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('test error');
        $promise->await();
    }

    public function testRejectWithRuntimeException(): void
    {
        $promise = Promise::reject(new \RuntimeException('runtime error'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('runtime error');
        $promise->await();
    }

    public function testRejectWithInvalidArgumentException(): void
    {
        $promise = Promise::reject(new \InvalidArgumentException('invalid arg'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid arg');
        $promise->await();
    }

    public function testRejectCanBeCaught(): void
    {
        $promise = Promise::reject(new \Exception('caught error'))
            ->catch(fn (\Throwable $e) => 'caught: ' . $e->getMessage());

        $this->assertEquals('caught: caught error', $promise->await());
    }

    public function testRejectThenCatch(): void
    {
        $promise = Promise::reject(new \Exception('error'))
            ->then(fn ($v) => 'should not reach')
            ->catch(fn ($e) => 'recovered');

        $this->assertEquals('recovered', $promise->await());
    }

    public function testMapWithCallables(): void
    {
        $promise = Promise::map([
            fn () => 1,
            fn () => 2,
            fn () => 3,
        ]);

        $this->assertEquals([1, 2, 3], $promise->await());
    }

    public function testMapWithEmptyArray(): void
    {
        $promise = Promise::map([]);
        $this->assertEquals([], $promise->await());
    }

    public function testMapWithSingleCallable(): void
    {
        $promise = Promise::map([fn () => 'single']);
        $this->assertEquals(['single'], $promise->await());
    }

    public function testMapWithComplexCallables(): void
    {
        $promise = Promise::map([
            fn () => ['nested' => 'array'],
            fn () => (object) ['prop' => 'value'],
            fn () => 42,
        ]);

        $results = $promise->await();

        $this->assertIsArray($results);
        $this->assertCount(3, $results);
        $this->assertEquals(['nested' => 'array'], $results[0]);
        $this->assertInstanceOf(\stdClass::class, $results[1]);
        /** @var \stdClass $secondResult */
        $secondResult = $results[1];
        $this->assertEquals('value', $secondResult->prop);
        $this->assertEquals(42, $results[2]);
    }

    public function testMapPreservesOrder(): void
    {
        $promise = Promise::map([
            fn () => 'first',
            fn () => 'second',
            fn () => 'third',
            fn () => 'fourth',
            fn () => 'fifth',
        ]);

        $this->assertEquals(['first', 'second', 'third', 'fourth', 'fifth'], $promise->await());
    }

    // all() edge cases

    public function testAllWithEmptyArray(): void
    {
        $promise = Promise::all([]);
        $this->assertEquals([], $promise->await());
    }

    public function testAllWithSinglePromise(): void
    {
        $promise = Promise::all([Promise::resolve('only')]);
        $this->assertEquals(['only'], $promise->await());
    }

    public function testAllWithManyPromises(): void
    {
        $promises = [];
        for ($i = 0; $i < 100; $i++) {
            $promises[] = Promise::resolve($i);
        }

        $results = Promise::all($promises)->await();

        $this->assertIsArray($results);
        $this->assertCount(100, $results);
        for ($i = 0; $i < 100; $i++) {
            $this->assertEquals($i, $results[$i]);
        }
    }

    public function testAllPreservesAssociativeKeys(): void
    {
        $promises = [
            'alpha' => Promise::resolve('a'),
            'beta' => Promise::resolve('b'),
            'gamma' => Promise::resolve('c'),
        ];

        $results = Promise::all($promises)->await();

        $this->assertIsArray($results);
        $this->assertEquals('a', $results['alpha']);
        $this->assertEquals('b', $results['beta']);
        $this->assertEquals('c', $results['gamma']);
    }

    public function testAllFailsOnFirstRejection(): void
    {
        $promises = [
            Promise::resolve(1),
            Promise::reject(new \Exception('fail')),
            Promise::resolve(3),
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('fail');
        Promise::all($promises)->await();
    }

    // allSettled() edge cases

    public function testAllSettledWithEmptyArray(): void
    {
        $results = Promise::allSettled([])->await();
        $this->assertEquals([], $results);
    }

    public function testAllSettledWithMixedResults(): void
    {
        $promises = [
            Promise::resolve('success1'),
            Promise::reject(new \Exception('error1')),
            Promise::resolve('success2'),
            Promise::reject(new \RuntimeException('error2')),
        ];

        $results = Promise::allSettled($promises)->await();

        $this->assertIsArray($results);
        $this->assertCount(4, $results);
        $this->assertIsArray($results[0]);
        $this->assertIsArray($results[1]);
        $this->assertIsArray($results[2]);
        $this->assertIsArray($results[3]);
        $this->assertEquals('fulfilled', $results[0]['status']);
        $this->assertEquals('success1', $results[0]['value']);
        $this->assertEquals('rejected', $results[1]['status']);
        $this->assertInstanceOf(\Exception::class, $results[1]['reason']);
        $this->assertEquals('fulfilled', $results[2]['status']);
        $this->assertEquals('rejected', $results[3]['status']);
    }

    public function testAllSettledNeverRejects(): void
    {
        $promises = [
            Promise::reject(new \Exception('error1')),
            Promise::reject(new \Exception('error2')),
            Promise::reject(new \Exception('error3')),
        ];

        // Should not throw, even with all rejections
        $results = Promise::allSettled($promises)->await();

        $this->assertIsArray($results);
        $this->assertCount(3, $results);
        foreach ($results as $result) {
            $this->assertIsArray($result);
            $this->assertEquals('rejected', $result['status']);
        }
    }

    // race() edge cases

    public function testRaceWithSinglePromise(): void
    {
        $result = Promise::race([Promise::resolve('only')])->await();
        $this->assertEquals('only', $result);
    }

    public function testRaceWithRejectedFirst(): void
    {
        $promises = [
            Promise::reject(new \Exception('first fails')),
            Promise::resolve('second'),
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('first fails');
        Promise::race($promises)->await();
    }

    // any() edge cases

    public function testAnyWithEmptyArrayThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No promises provided to any()');
        Promise::any([])->await();
    }

    public function testAnyWithAllRejected(): void
    {
        $promises = [
            Promise::reject(new \Exception('error1')),
            Promise::reject(new \Exception('error2')),
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('All promises were rejected');
        Promise::any($promises)->await();
    }

    public function testAnyReturnsFirstFulfilled(): void
    {
        $promises = [
            Promise::reject(new \Exception('skip')),
            Promise::resolve('winner'),
            Promise::resolve('also good'),
        ];

        $result = Promise::any($promises)->await();
        $this->assertEquals('winner', $result);
    }

    // async() edge cases

    public function testAsyncWithReturnNull(): void
    {
        $promise = Promise::async(fn () => null);
        $this->assertNull($promise->await());
    }

    public function testAsyncWithException(): void
    {
        $promise = Promise::async(function () {
            throw new \RuntimeException('async threw');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('async threw');
        $promise->await();
    }

    public function testAsyncExceptionCanBeCaught(): void
    {
        $promise = Promise::async(function () {
            throw new \Exception('caught me');
        })->catch(fn (\Throwable $e) => 'caught: ' . $e->getMessage());

        $this->assertEquals('caught: caught me', $promise->await());
    }

    // run() edge cases

    public function testRunWithReturnValue(): void
    {
        $result = Promise::run(fn () => 'direct result');
        $this->assertEquals('direct result', $result);
    }

    public function testRunWithException(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('run threw');

        Promise::run(function () {
            throw new \LogicException('run threw');
        });
    }

    public function testRunWithComplexComputation(): void
    {
        $result = Promise::run(function () {
            $sum = 0;
            for ($i = 1; $i <= 100; $i++) {
                $sum += $i;
            }
            return $sum;
        });

        $this->assertEquals(5050, $result);
    }

    // delay() tests

    public function testDelayWithZero(): void
    {
        $start = microtime(true);
        Promise::delay(0)->await();
        $elapsed = (microtime(true) - $start) * 1000;

        // Should complete nearly instantly
        $this->assertLessThan(50, $elapsed);
    }

    public function testDelayWithSmallValue(): void
    {
        $start = microtime(true);
        Promise::delay(50)->await();
        $elapsed = (microtime(true) - $start) * 1000;

        $this->assertGreaterThanOrEqual(50, $elapsed);
    }

    // Chaining edge cases

    public function testDeepChaining(): void
    {
        $promise = Promise::resolve(1);

        for ($i = 0; $i < 10; $i++) {
            $promise = $promise->then(fn (int $v) => $v + 1);
        }

        $this->assertEquals(11, $promise->await());
    }

    public function testChainingThenCatchFinally(): void
    {
        $order = [];

        $promise = Promise::resolve('start')
            ->then(function (string $v) use (&$order) {
                $order[] = 'then1';
                return $v . '-then1';
            })
            ->then(function (string $v) use (&$order) {
                $order[] = 'then2';
                return $v . '-then2';
            })
            ->finally(function () use (&$order) {
                $order[] = 'finally';
            });

        $result = $promise->await();

        $this->assertEquals('start-then1-then2', $result);
        $this->assertEquals(['then1', 'then2', 'finally'], $order);
    }

    public function testCatchMiddleOfChain(): void
    {
        $promise = Promise::resolve('start')
            ->then(function (string $v) {
                throw new \Exception('mid-chain error');
            })
            ->catch(fn (\Throwable $e) => 'recovered')
            ->then(fn (string $v) => $v . '-continued');

        $this->assertEquals('recovered-continued', $promise->await());
    }

    // Type coercion edge cases

    public function testResolveWithResource(): void
    {
        $resource = fopen('php://memory', 'r');
        $this->assertIsResource($resource);
        $promise = Promise::resolve($resource);
        $result = $promise->await();
        $this->assertIsResource($result);
        fclose($result);
    }

    public function testResolveWithClosure(): void
    {
        $closure = fn () => 'I am a closure';
        $promise = Promise::resolve($closure);
        $result = $promise->await();
        $this->assertIsCallable($result);
        $this->assertEquals('I am a closure', $result());
    }

    public function testResolveWithLargeArray(): void
    {
        $largeArray = range(1, 10000);
        $promise = Promise::resolve($largeArray);
        $result = $promise->await();
        $this->assertIsArray($result);
        $this->assertCount(10000, $result);
        $this->assertEquals(1, $result[0]);
        $this->assertEquals(10000, $result[9999]);
    }

    public function testResolveWithDeepNestedStructure(): void
    {
        $deep = ['level1' => ['level2' => ['level3' => ['level4' => ['value' => 'found']]]]];
        $promise = Promise::resolve($deep);
        $result = $promise->await();
        $this->assertIsArray($result);
        /** @var array{level1: array{level2: array{level3: array{level4: array{value: string}}}}} $result */
        $this->assertEquals('found', $result['level1']['level2']['level3']['level4']['value']);
    }

    /**
     * A+ 2.3.1: If promise and x refer to the same object, reject with TypeError
     *
     * Note: This is tested by directly invoking the resolve callback with the promise instance.
     * In real usage, this would happen when a then() callback returns the promise it's chained from.
     */
    public function testSelfReferenceRejectsWithTypeError(): void
    {
        Promise::setAdapter(Sync::class);

        // Create an unresolved promise and capture the resolve function
        $resolveFunc = null;
        $promise = new Sync(function (callable $resolve, callable $reject) use (&$resolveFunc) {
            $resolveFunc = $resolve;
        });

        // Now resolve with itself - this should reject with TypeError
        \assert(\is_callable($resolveFunc));
        $resolveFunc($promise);

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('A promise cannot be resolved with itself');
        $promise->await();
    }

    /**
     * A+ 2.3.2: If x is a promise, adopt its state (fulfilled)
     */
    public function testResolveWithFulfilledPromise(): void
    {
        Promise::setAdapter(Sync::class);

        $innerPromise = Promise::resolve('inner value');
        $outerPromise = Promise::resolve($innerPromise);

        $this->assertEquals('inner value', $outerPromise->await());
    }

    /**
     * A+ 2.3.2: If x is a promise, adopt its state (rejected)
     */
    public function testResolveWithRejectedPromise(): void
    {
        Promise::setAdapter(Sync::class);

        $innerPromise = Promise::reject(new \Exception('inner error'));
        $outerPromise = Promise::resolve($innerPromise);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('inner error');
        $outerPromise->await();
    }

    /**
     * A+ 2.3.3: If x is a thenable, call its then method
     */
    public function testResolveWithThenable(): void
    {
        Promise::setAdapter(Sync::class);

        // Create a custom thenable object
        $thenable = new class () {
            public function then(callable $onFulfilled, callable $onRejected): void
            {
                $onFulfilled('thenable value');
            }
        };

        $promise = Promise::resolve($thenable);

        $this->assertEquals('thenable value', $promise->await());
    }

    /**
     * A+ 2.3.3: If thenable's then method rejects
     */
    public function testResolveWithThenableThatRejects(): void
    {
        Promise::setAdapter(Sync::class);

        $thenable = new class () {
            public function then(callable $onFulfilled, callable $onRejected): void
            {
                $onRejected(new \Exception('thenable error'));
            }
        };

        $promise = Promise::resolve($thenable);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('thenable error');
        $promise->await();
    }

    /**
     * A+ 2.3.3.3.3: If both resolvePromise and rejectPromise are called, first call wins
     */
    public function testThenableFirstCallWins(): void
    {
        Promise::setAdapter(Sync::class);

        $thenable = new class () {
            public function then(callable $onFulfilled, callable $onRejected): void
            {
                $onFulfilled('first');
                $onFulfilled('second'); // Should be ignored
                $onRejected(new \Exception('third')); // Should be ignored
            }
        };

        $promise = Promise::resolve($thenable);

        $this->assertEquals('first', $promise->await());
    }

    /**
     * A+ 2.3.3.3.4: If thenable's then throws, reject (unless already resolved)
     */
    public function testThenableThrowsException(): void
    {
        Promise::setAdapter(Sync::class);

        $thenable = new class () {
            public function then(callable $onFulfilled, callable $onRejected): void
            {
                throw new \RuntimeException('thenable threw');
            }
        };

        $promise = Promise::resolve($thenable);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('thenable threw');
        $promise->await();
    }

    /**
     * A+ 2.3.3.3.4.1: If thenable throws after resolve, exception is ignored
     */
    public function testThenableThrowsAfterResolve(): void
    {
        Promise::setAdapter(Sync::class);

        $thenable = new class () {
            public function then(callable $onFulfilled, callable $onRejected): void
            {
                $onFulfilled('resolved first');
                throw new \RuntimeException('thrown after'); // Should be ignored
            }
        };

        $promise = Promise::resolve($thenable);

        $this->assertEquals('resolved first', $promise->await());
    }

    /**
     * A+ 2.2.7.3: If onFulfilled is not a function, promise2 must be fulfilled with same value
     */
    public function testThenWithNullOnFulfilledPropagatesValue(): void
    {
        Promise::setAdapter(Sync::class);

        $promise = Promise::resolve('original')
            ->then(null, fn ($e) => 'error handler')
            ->then(fn (string $v): string => $v . ' passed');

        $this->assertEquals('original passed', $promise->await());
    }

    /**
     * A+ 2.2.7.4: If onRejected is not a function, promise2 must be rejected with same reason
     */
    public function testThenWithNullOnRejectedPropagatesReason(): void
    {
        Promise::setAdapter(Sync::class);

        $promise = Promise::reject(new \Exception('original error'))
            ->then(fn ($v) => 'success handler', null)
            ->catch(fn (\Throwable $e): string => 'caught: ' . $e->getMessage());

        $this->assertEquals('caught: original error', $promise->await());
    }

    /**
     * A+ 2.2.7.1: If onFulfilled returns a promise, promise2 adopts its state
     */
    public function testThenReturningPromise(): void
    {
        Promise::setAdapter(Sync::class);

        $promise = Promise::resolve('start')
            ->then(fn ($v) => Promise::resolve('chained promise'));

        $this->assertEquals('chained promise', $promise->await());
    }

    /**
     * A+ 2.3.3.1: Recursive thenable resolution
     */
    public function testRecursiveThenableResolution(): void
    {
        Promise::setAdapter(Sync::class);

        // Thenable that resolves to another thenable
        $innerThenable = new class () {
            public function then(callable $onFulfilled, callable $onRejected): void
            {
                $onFulfilled('deeply nested value');
            }
        };

        $outerThenable = new class ($innerThenable) {
            private object $inner;

            public function __construct(object $inner)
            {
                $this->inner = $inner;
            }

            public function then(callable $onFulfilled, callable $onRejected): void
            {
                $onFulfilled($this->inner);
            }
        };

        $promise = Promise::resolve($outerThenable);

        $this->assertEquals('deeply nested value', $promise->await());
    }
}
