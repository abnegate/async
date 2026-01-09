<?php

namespace Utopia\Tests\E2e\Timer\Swoole;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine as SwooleCoroutine;
use Utopia\Async\Timer\Adapter\Swoole\Coroutine;

/**
 * @group swoole
 */
class CoroutineTest extends TestCase
{
    protected function setUp(): void
    {
        if (!Coroutine::isSupported()) {
            $this->markTestSkipped('Swoole extension is not available');
        }
        Coroutine::clearAll();
    }

    protected function tearDown(): void
    {
        if (Coroutine::isSupported()) {
            Coroutine::clearAll();
        }
    }

    public function testIsSupported(): void
    {
        $this->assertTrue(Coroutine::isSupported());
    }

    public function testAfter(): void
    {
        SwooleCoroutine\run(function () {
            $executed = false;
            $start = microtime(true);

            Coroutine::after(50, function () use (&$executed) {
                $executed = true;
            });

            // Wait for timer to execute
            SwooleCoroutine::sleep(0.1);

            $this->assertTrue($executed);
        });
    }

    public function testTick(): void
    {
        SwooleCoroutine\run(function () {
            $count = 0;

            $timerId = Coroutine::tick(20, function (int $id) use (&$count) {
                $count++;
                if ($count >= 3) {
                    Coroutine::clear($id);
                }
            });

            // Wait for ticks to execute
            SwooleCoroutine::sleep(0.15);

            $this->assertGreaterThanOrEqual(3, $count);
        });
    }

    public function testClear(): void
    {
        SwooleCoroutine\run(function () {
            $executed = false;

            $timerId = Coroutine::after(100, function () use (&$executed) {
                $executed = true;
            });

            $this->assertTrue(Coroutine::exists($timerId));
            $this->assertTrue(Coroutine::clear($timerId));
            $this->assertFalse(Coroutine::exists($timerId));

            // Wait to ensure callback would have fired
            SwooleCoroutine::sleep(0.15);

            $this->assertFalse($executed);
        });
    }

    public function testClearAll(): void
    {
        SwooleCoroutine\run(function () {
            $executed1 = false;
            $executed2 = false;

            Coroutine::after(100, function () use (&$executed1) {
                $executed1 = true;
            });

            Coroutine::after(100, function () use (&$executed2) {
                $executed2 = true;
            });

            Coroutine::clearAll();
            $this->assertEquals([], Coroutine::getTimers());

            // Wait to ensure callbacks would have fired
            SwooleCoroutine::sleep(0.15);

            $this->assertFalse($executed1);
            $this->assertFalse($executed2);
        });
    }

    public function testExists(): void
    {
        SwooleCoroutine\run(function () {
            $this->assertFalse(Coroutine::exists(999));

            $timerId = Coroutine::after(100, function () {});

            $this->assertTrue(Coroutine::exists($timerId));

            Coroutine::clear($timerId);

            $this->assertFalse(Coroutine::exists($timerId));
        });
    }

    public function testGetTimers(): void
    {
        SwooleCoroutine\run(function () {
            $this->assertEquals([], Coroutine::getTimers());

            $timerId1 = Coroutine::after(100, function () {});
            $timerId2 = Coroutine::after(100, function () {});

            $timers = Coroutine::getTimers();
            $this->assertContains($timerId1, $timers);
            $this->assertContains($timerId2, $timers);

            Coroutine::clearAll();
        });
    }

    public function testAfterReturnsTimerId(): void
    {
        SwooleCoroutine\run(function () {
            $timerId = Coroutine::after(100, function () {});

            $this->assertIsInt($timerId);
            $this->assertGreaterThan(0, $timerId);

            Coroutine::clear($timerId);
        });
    }

    public function testTickReturnsTimerId(): void
    {
        SwooleCoroutine\run(function () {
            $timerId = Coroutine::tick(100, function () {});

            $this->assertIsInt($timerId);
            $this->assertGreaterThan(0, $timerId);

            Coroutine::clear($timerId);
        });
    }

    public function testTimerIdsAreUnique(): void
    {
        SwooleCoroutine\run(function () {
            $ids = [];

            for ($i = 0; $i < 5; $i++) {
                $ids[] = Coroutine::after(100, function () {});
            }

            $this->assertCount(5, array_unique($ids));

            Coroutine::clearAll();
        });
    }

    public function testCallbackReceivesTimerId(): void
    {
        SwooleCoroutine\run(function () {
            $receivedId = null;
            $expectedId = null;

            $expectedId = Coroutine::tick(10, function (int $id) use (&$receivedId) {
                $receivedId = $id;
                Coroutine::clear($id);
            });

            SwooleCoroutine::sleep(0.05);

            $this->assertEquals($expectedId, $receivedId);
        });
    }

    public function testConcurrentTimers(): void
    {
        SwooleCoroutine\run(function () {
            $results = [];

            Coroutine::after(30, function () use (&$results) {
                $results[] = 'third';
            });

            Coroutine::after(10, function () use (&$results) {
                $results[] = 'first';
            });

            Coroutine::after(20, function () use (&$results) {
                $results[] = 'second';
            });

            SwooleCoroutine::sleep(0.05);

            // In async mode, timers execute in order of their delays
            $this->assertEquals(['first', 'second', 'third'], $results);
        });
    }
}
