<?php

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Async\Promise\Adapter;
use Utopia\Async\Promise\Configuration;

/**
 * Test class that extends Adapter to access protected methods
 */
class TestablePromiseAdapter extends Adapter
{
    protected function execute(callable $executor, callable $resolve, callable $reject): void
    {
        try {
            $executor($resolve, $reject);
        } catch (\Throwable $e) {
            $reject($e);
        }
    }

    protected function sleep(): void
    {
        usleep(100);
    }

    public static function delay(int $milliseconds): static
    {
        return new static(function (callable $resolve) use ($milliseconds) {
            usleep($milliseconds * 1000);
            $resolve(null);
        });
    }

    public static function all(array $promises): static
    {
        return new static(function (callable $resolve, callable $reject) use ($promises) {
            $results = [];
            $remaining = count($promises);

            if ($remaining === 0) {
                $resolve([]);
                return;
            }

            foreach ($promises as $index => $promise) {
                $promise->then(
                    function ($value) use (&$results, &$remaining, $index, $resolve) {
                        $results[$index] = $value;
                        if (--$remaining === 0) {
                            ksort($results);
                            $resolve(array_values($results));
                        }
                    },
                    function ($reason) use ($reject) {
                        $reject($reason);
                    }
                );
            }
        });
    }

    public static function race(array $promises): static
    {
        return new static(function (callable $resolve, callable $reject) use ($promises) {
            foreach ($promises as $promise) {
                $promise->then($resolve, $reject);
            }
        });
    }

    public static function allSettled(array $promises): static
    {
        return new static(function (callable $resolve) use ($promises) {
            $results = [];
            $remaining = count($promises);

            if ($remaining === 0) {
                $resolve([]);
                return;
            }

            foreach ($promises as $index => $promise) {
                $promise->then(
                    function ($value) use (&$results, &$remaining, $index, $resolve) {
                        $results[$index] = ['status' => 'fulfilled', 'value' => $value];
                        if (--$remaining === 0) {
                            ksort($results);
                            $resolve(array_values($results));
                        }
                    },
                    function ($reason) use (&$results, &$remaining, $index, $resolve) {
                        $results[$index] = ['status' => 'rejected', 'reason' => $reason];
                        if (--$remaining === 0) {
                            ksort($results);
                            $resolve(array_values($results));
                        }
                    }
                );
            }
        });
    }

    public static function any(array $promises): static
    {
        return new static(function (callable $resolve, callable $reject) use ($promises) {
            $errors = [];
            $remaining = count($promises);

            if ($remaining === 0) {
                $reject(new \Exception('All promises rejected'));
                return;
            }

            foreach ($promises as $index => $promise) {
                $promise->then(
                    $resolve,
                    function ($reason) use (&$errors, &$remaining, $index, $reject) {
                        $errors[$index] = $reason;
                        if (--$remaining === 0) {
                            $reject(new \Exception('All promises rejected'));
                        }
                    }
                );
            }
        });
    }

    public function exposeIsPending(): bool
    {
        return $this->isPending();
    }

    public function exposeIsFulfilled(): bool
    {
        return $this->isFulfilled();
    }

    public function exposeIsRejected(): bool
    {
        return $this->isRejected();
    }

    public function exposeState(): int
    {
        return $this->state;
    }
}

class PromiseAdapterTest extends TestCase
{
    public function testAdapterIsAbstract(): void
    {
        $reflection = new \ReflectionClass(Adapter::class);

        $this->assertTrue($reflection->isAbstract());
    }

    public function testAbstractMethods(): void
    {
        $reflection = new \ReflectionClass(Adapter::class);

        $this->assertTrue($reflection->getMethod('execute')->isAbstract());
        $this->assertTrue($reflection->getMethod('sleep')->isAbstract());
        $this->assertTrue($reflection->getMethod('delay')->isAbstract());
        $this->assertTrue($reflection->getMethod('all')->isAbstract());
        $this->assertTrue($reflection->getMethod('race')->isAbstract());
        $this->assertTrue($reflection->getMethod('allSettled')->isAbstract());
        $this->assertTrue($reflection->getMethod('any')->isAbstract());
    }

    public function testProtectedMethods(): void
    {
        $reflection = new \ReflectionClass(Adapter::class);

        $this->assertTrue($reflection->getMethod('execute')->isProtected());
        $this->assertTrue($reflection->getMethod('sleep')->isProtected());
        $this->assertTrue($reflection->getMethod('isPending')->isProtected());
        $this->assertTrue($reflection->getMethod('isFulfilled')->isProtected());
        $this->assertTrue($reflection->getMethod('isRejected')->isProtected());
    }

    public function testStateConstants(): void
    {
        $reflection = new \ReflectionClass(Adapter::class);

        $constants = $reflection->getConstants();

        $this->assertArrayHasKey('STATE_PENDING', $constants);
        $this->assertArrayHasKey('STATE_FULFILLED', $constants);
        $this->assertArrayHasKey('STATE_REJECTED', $constants);

        $this->assertEquals(1, $constants['STATE_PENDING']);
        $this->assertEquals(0, $constants['STATE_FULFILLED']);
        $this->assertEquals(-1, $constants['STATE_REJECTED']);
    }

    public function testSleepDurationConfiguration(): void
    {
        // Test default values via Configuration class
        $this->assertEquals(100, Configuration::getSleepDurationUs());
        $this->assertEquals(10000, Configuration::getMaxSleepDurationUs());
        $this->assertEquals(0.001, Configuration::getCoroutineSleepDurationS());

        // Test setting custom values
        Configuration::setSleepDurationUs(200);
        Configuration::setMaxSleepDurationUs(20000);
        Configuration::setCoroutineSleepDurationS(0.002);

        $this->assertEquals(200, Configuration::getSleepDurationUs());
        $this->assertEquals(20000, Configuration::getMaxSleepDurationUs());
        $this->assertEquals(0.002, Configuration::getCoroutineSleepDurationS());

        // Reset to defaults
        Configuration::reset();

        $this->assertEquals(100, Configuration::getSleepDurationUs());
        $this->assertEquals(10000, Configuration::getMaxSleepDurationUs());
        $this->assertEquals(0.001, Configuration::getCoroutineSleepDurationS());
    }

    public function testConstructorWithNullExecutor(): void
    {
        $promise = new TestablePromiseAdapter(null);

        $this->assertTrue($promise->exposeIsPending());
    }

    public function testConstructorWithExecutor(): void
    {
        $promise = new TestablePromiseAdapter(function (callable $resolve) {
            $resolve('test');
        });

        $this->assertTrue($promise->exposeIsFulfilled());
        $this->assertEquals('test', $promise->await());
    }

    public function testInitialStateIsPending(): void
    {
        $promise = new TestablePromiseAdapter(null);

        $this->assertTrue($promise->exposeIsPending());
        $this->assertFalse($promise->exposeIsFulfilled());
        $this->assertFalse($promise->exposeIsRejected());
    }

    public function testResolveChangesStateToFulfilled(): void
    {
        $promise = new TestablePromiseAdapter(function (callable $resolve) {
            $resolve('value');
        });

        $this->assertFalse($promise->exposeIsPending());
        $this->assertTrue($promise->exposeIsFulfilled());
        $this->assertFalse($promise->exposeIsRejected());
    }

    public function testRejectChangesStateToRejected(): void
    {
        $promise = new TestablePromiseAdapter(function (callable $resolve, callable $reject) {
            $reject(new \Exception('error'));
        });

        $this->assertFalse($promise->exposeIsPending());
        $this->assertFalse($promise->exposeIsFulfilled());
        $this->assertTrue($promise->exposeIsRejected());
    }

    public function testCreateReturnsNewInstance(): void
    {
        $promise = TestablePromiseAdapter::create(function (callable $resolve) {
            $resolve('created');
        });

        $this->assertInstanceOf(TestablePromiseAdapter::class, $promise);
        $this->assertEquals('created', $promise->await());
    }

    public function testResolveCreatesResolvedPromise(): void
    {
        $promise = TestablePromiseAdapter::resolve('resolved value');

        $this->assertTrue($promise->exposeIsFulfilled());
        $this->assertEquals('resolved value', $promise->await());
    }

    public function testResolveWithNull(): void
    {
        $promise = TestablePromiseAdapter::resolve(null);

        $this->assertTrue($promise->exposeIsFulfilled());
        $this->assertNull($promise->await());
    }

    public function testResolveWithArray(): void
    {
        $data = ['key' => 'value', 'nested' => [1, 2, 3]];
        $promise = TestablePromiseAdapter::resolve($data);

        $this->assertEquals($data, $promise->await());
    }

    public function testRejectCreatesRejectedPromise(): void
    {
        $exception = new \Exception('rejected reason');
        $promise = TestablePromiseAdapter::reject($exception);

        $this->assertTrue($promise->exposeIsRejected());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('rejected reason');
        $promise->await();
    }

    public function testAsyncExecutesCallable(): void
    {
        $promise = TestablePromiseAdapter::async(function () {
            return 42;
        });

        $this->assertEquals(42, $promise->await());
    }

    public function testAsyncCatchesExceptions(): void
    {
        $promise = TestablePromiseAdapter::async(function () {
            throw new \RuntimeException('async error');
        });

        $this->assertTrue($promise->exposeIsRejected());

        $this->expectException(\RuntimeException::class);
        $promise->await();
    }

    public function testRunExecutesAndAwaits(): void
    {
        $result = TestablePromiseAdapter::run(function () {
            return 'run result';
        });

        $this->assertEquals('run result', $result);
    }

    public function testRunPropagatesExceptions(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('run error');

        TestablePromiseAdapter::run(function () {
            throw new \LogicException('run error');
        });
    }

    public function testThenWithFulfilledPromise(): void
    {
        $promise = TestablePromiseAdapter::resolve(10)
            ->then(fn (int $v) => $v * 2);

        $this->assertEquals(20, $promise->await());
    }

    public function testThenChaining(): void
    {
        $promise = TestablePromiseAdapter::resolve(5)
            ->then(fn (int $v) => $v + 5)
            ->then(fn (int $v) => $v * 2)
            ->then(fn (int $v) => "result: {$v}");

        $this->assertEquals('result: 20', $promise->await());
    }

    public function testThenWithNullOnFulfilledReturnsSamePromise(): void
    {
        $original = TestablePromiseAdapter::resolve('value');
        $result = $original->then(null);

        $this->assertEquals('value', $result->await());
    }

    public function testThenWithNullOnRejectedReturnsSamePromise(): void
    {
        $original = TestablePromiseAdapter::reject(new \Exception('error'));
        $result = $original->then(null);

        $this->assertTrue($result->exposeIsRejected());
    }

    public function testThenCatchesCallbackExceptions(): void
    {
        $promise = TestablePromiseAdapter::resolve('value')
            ->then(function () {
                throw new \Exception('then error');
            });

        $this->assertTrue($promise->exposeIsRejected());
    }

    public function testCatchHandlesRejection(): void
    {
        $handled = false;
        $promise = TestablePromiseAdapter::reject(new \Exception('catch me'))
            ->catch(function ($e) use (&$handled) {
                $handled = true;
                return 'recovered';
            });

        $this->assertEquals('recovered', $promise->await());
        $this->assertTrue($handled);
    }

    public function testCatchIsNotCalledOnFulfilled(): void
    {
        $called = false;
        $promise = TestablePromiseAdapter::resolve('success')
            ->catch(function () use (&$called) {
                $called = true;
                return 'caught';
            });

        $this->assertEquals('success', $promise->await());
        $this->assertFalse($called);
    }

    public function testFinallyCalledOnFulfilled(): void
    {
        $finallyCalled = false;
        $promise = TestablePromiseAdapter::resolve('value')
            ->finally(function () use (&$finallyCalled) {
                $finallyCalled = true;
            });

        $result = $promise->await();

        $this->assertTrue($finallyCalled);
        $this->assertEquals('value', $result);
    }

    public function testFinallyCalledOnRejected(): void
    {
        $finallyCalled = false;
        $promise = TestablePromiseAdapter::reject(new \Exception('error'))
            ->finally(function () use (&$finallyCalled) {
                $finallyCalled = true;
            });

        try {
            $promise->await();
        } catch (\Exception $e) {
            // Expected
        }

        $this->assertTrue($finallyCalled);
    }

    public function testFinallyPreservesResolvedValue(): void
    {
        $promise = TestablePromiseAdapter::resolve('original')
            ->finally(function () {
                return 'ignored';
            });

        $this->assertEquals('original', $promise->await());
    }

    public function testFinallyPreservesRejection(): void
    {
        $promise = TestablePromiseAdapter::reject(new \Exception('original error'))
            ->finally(function () {
                // Do nothing
            });

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('original error');
        $promise->await();
    }

    public function testAwaitReturnsResolvedValue(): void
    {
        $promise = TestablePromiseAdapter::resolve(['data' => 123]);

        $result = $promise->await();

        $this->assertEquals(['data' => 123], $result);
    }

    public function testAwaitThrowsOnRejection(): void
    {
        $promise = TestablePromiseAdapter::reject(new \InvalidArgumentException('invalid'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid');
        $promise->await();
    }

    public function testDelayCreatesDelayedPromise(): void
    {
        $start = microtime(true);
        $promise = TestablePromiseAdapter::delay(50);
        $promise->await();
        $elapsed = (microtime(true) - $start) * 1000;

        $this->assertGreaterThanOrEqual(40, $elapsed);
    }

    public function testTimeoutResolvesBeforeDeadline(): void
    {
        $promise = TestablePromiseAdapter::resolve('quick')
            ->timeout(1000);

        $this->assertEquals('quick', $promise->await());
    }

    public function testAllWithEmptyArray(): void
    {
        $promise = TestablePromiseAdapter::all([]);

        $this->assertEquals([], $promise->await());
    }

    public function testAllResolvesWithAllValues(): void
    {
        $promise = TestablePromiseAdapter::all([
            TestablePromiseAdapter::resolve('a'),
            TestablePromiseAdapter::resolve('b'),
            TestablePromiseAdapter::resolve('c'),
        ]);

        $this->assertEquals(['a', 'b', 'c'], $promise->await());
    }

    public function testAllRejectsOnFirstRejection(): void
    {
        $promise = TestablePromiseAdapter::all([
            TestablePromiseAdapter::resolve('ok'),
            TestablePromiseAdapter::reject(new \Exception('failed')),
            TestablePromiseAdapter::resolve('also ok'),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('failed');
        $promise->await();
    }

    public function testRaceResolvesWithFirstSettled(): void
    {
        $promise = TestablePromiseAdapter::race([
            TestablePromiseAdapter::resolve('first'),
            TestablePromiseAdapter::delay(100)->then(fn () => 'second'),
        ]);

        $this->assertEquals('first', $promise->await());
    }

    public function testAllSettledWithEmptyArray(): void
    {
        $promise = TestablePromiseAdapter::allSettled([]);

        $this->assertEquals([], $promise->await());
    }

    public function testAllSettledReturnsAllResults(): void
    {
        $promise = TestablePromiseAdapter::allSettled([
            TestablePromiseAdapter::resolve('success'),
            TestablePromiseAdapter::reject(new \Exception('failure')),
        ]);

        $results = $promise->await();

        $this->assertIsArray($results);
        $this->assertCount(2, $results);
        $this->assertIsArray($results[0]);
        $this->assertIsArray($results[1]);
        $this->assertEquals('fulfilled', $results[0]['status']);
        $this->assertEquals('success', $results[0]['value']);
        $this->assertEquals('rejected', $results[1]['status']);
        $this->assertInstanceOf(\Exception::class, $results[1]['reason']);
    }

    public function testAnyResolvesWithFirstSuccess(): void
    {
        $promise = TestablePromiseAdapter::any([
            TestablePromiseAdapter::reject(new \Exception('fail 1')),
            TestablePromiseAdapter::resolve('success'),
            TestablePromiseAdapter::reject(new \Exception('fail 2')),
        ]);

        $this->assertEquals('success', $promise->await());
    }

    public function testAnyRejectsWhenAllReject(): void
    {
        $promise = TestablePromiseAdapter::any([
            TestablePromiseAdapter::reject(new \Exception('fail 1')),
            TestablePromiseAdapter::reject(new \Exception('fail 2')),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('All promises rejected');
        $promise->await();
    }

    public function testAnyWithEmptyArrayRejects(): void
    {
        $promise = TestablePromiseAdapter::any([]);

        $this->expectException(\Exception::class);
        $promise->await();
    }

    public function testPromiseCanOnlySettleOnce(): void
    {
        /** @var callable|null $resolveFunc */
        $resolveFunc = null;
        $promise = new TestablePromiseAdapter(function (callable $resolve) use (&$resolveFunc) {
            $resolveFunc = $resolve;
            $resolve('first');
        });

        if ($resolveFunc !== null) {
            $resolveFunc('second');
        }

        $this->assertEquals('first', $promise->await());
    }

    public function testPromiseChainWithNestedPromise(): void
    {
        $promise = TestablePromiseAdapter::resolve('outer')
            ->then(function (string $value) {
                return TestablePromiseAdapter::resolve("{$value}-inner");
            });

        $this->assertEquals('outer-inner', $promise->await());
    }

    public function testThenWithBothCallbacks(): void
    {
        $onFulfilledCalled = false;
        $onRejectedCalled = false;

        $promise = TestablePromiseAdapter::resolve('value')
            ->then(
                function ($v) use (&$onFulfilledCalled) {
                    $onFulfilledCalled = true;
                    return $v;
                },
                function ($e) use (&$onRejectedCalled) {
                    $onRejectedCalled = true;
                    return $e;
                }
            );

        $promise->await();

        $this->assertTrue($onFulfilledCalled);
        $this->assertFalse($onRejectedCalled);
    }

    public function testThenOnRejectedWithBothCallbacks(): void
    {
        $onFulfilledCalled = false;
        $onRejectedCalled = false;

        $promise = TestablePromiseAdapter::reject(new \Exception('error'))
            ->then(
                function ($v) use (&$onFulfilledCalled) {
                    $onFulfilledCalled = true;
                    return $v;
                },
                function ($e) use (&$onRejectedCalled) {
                    $onRejectedCalled = true;
                    return 'recovered';
                }
            );

        $result = $promise->await();

        $this->assertFalse($onFulfilledCalled);
        $this->assertTrue($onRejectedCalled);
        $this->assertEquals('recovered', $result);
    }

    public function testResolveWithDifferentTypes(): void
    {
        $this->assertEquals(42, TestablePromiseAdapter::resolve(42)->await());
        $this->assertEquals(3.14, TestablePromiseAdapter::resolve(3.14)->await());
        $this->assertEquals('string', TestablePromiseAdapter::resolve('string')->await());
        $this->assertEquals(true, TestablePromiseAdapter::resolve(true)->await());
        $this->assertEquals(false, TestablePromiseAdapter::resolve(false)->await());

        $obj = new \stdClass();
        $obj->prop = 'value';
        $this->assertEquals($obj, TestablePromiseAdapter::resolve($obj)->await());
    }

    public function testLongChainExecution(): void
    {
        $promise = TestablePromiseAdapter::resolve(1);

        for ($i = 0; $i < 10; $i++) {
            $promise = $promise->then(fn (int $v) => $v + 1);
        }

        $this->assertEquals(11, $promise->await());
    }

    public function testCatchThenChain(): void
    {
        $promise = TestablePromiseAdapter::reject(new \Exception('initial error'))
            ->catch(fn (\Throwable $e) => 'caught: ' . $e->getMessage())
            ->then(fn (string $v) => strtoupper($v));

        $this->assertEquals('CAUGHT: INITIAL ERROR', $promise->await());
    }

    public function testMultipleCatchHandlers(): void
    {
        $firstCatchCalled = false;
        $secondCatchCalled = false;

        $promise = TestablePromiseAdapter::reject(new \Exception('error'))
            ->catch(function ($e) use (&$firstCatchCalled) {
                $firstCatchCalled = true;
                return 'handled';
            })
            ->catch(function ($e) use (&$secondCatchCalled) {
                $secondCatchCalled = true;
                return 'second handler';
            });

        $result = $promise->await();

        $this->assertTrue($firstCatchCalled);
        $this->assertFalse($secondCatchCalled);
        $this->assertEquals('handled', $result);
    }

    public function testCatchRethrows(): void
    {
        $promise = TestablePromiseAdapter::reject(new \Exception('original'))
            ->catch(function (\Throwable $e) {
                throw new \RuntimeException('rethrown: ' . $e->getMessage());
            });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rethrown: original');
        $promise->await();
    }
}
