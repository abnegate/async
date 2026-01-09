<?php

namespace Utopia\Tests\E2e\Timer;

use PHPUnit\Framework\TestCase;
use Utopia\Async\Timer\Adapter\Sync;

class SyncTest extends TestCase
{
    protected function setUp(): void
    {
        Sync::resetInstance();
    }

    protected function tearDown(): void
    {
        Sync::resetInstance();
    }

    public function testIsSupported(): void
    {
        $this->assertTrue(Sync::isSupported());
    }

    public function testAfter(): void
    {
        $executed = false;
        $start = microtime(true);

        Sync::after(50, function () use (&$executed) {
            $executed = true;
        });

        $elapsed = (microtime(true) - $start) * 1000;

        $this->assertTrue($executed);
        $this->assertGreaterThanOrEqual(45, $elapsed);
    }

    public function testAfterWithDifferentDelays(): void
    {
        $results = [];
        $start = microtime(true);

        Sync::after(20, function () use (&$results) {
            $results[] = 'first';
        });

        Sync::after(10, function () use (&$results) {
            $results[] = 'second';
        });

        $elapsed = (microtime(true) - $start) * 1000;

        // In sync mode, timers execute sequentially in call order
        $this->assertEquals(['first', 'second'], $results);
        $this->assertGreaterThanOrEqual(25, $elapsed);
    }

    public function testClear(): void
    {
        // Since Sync::after() is blocking, we can't really test clear() during execution
        // But we can test that clear() returns false for non-existent timers
        $result = Sync::clear(999);
        $this->assertFalse($result);
    }

    public function testClearAll(): void
    {
        // Test that clearAll doesn't throw
        Sync::clearAll();
        $this->assertEquals([], Sync::getTimers());
    }

    public function testExists(): void
    {
        // In sync mode, after() blocks until completion, so the timer won't exist after the call
        // Test that exists returns false for non-existent timers
        $this->assertFalse(Sync::exists(999));
    }

    public function testGetTimers(): void
    {
        // After all timers complete, the list should be empty
        Sync::after(10, function () {});
        $this->assertEquals([], Sync::getTimers());
    }

    public function testTickWithExternalClear(): void
    {
        $count = 0;

        // Use a closure that clears itself after 3 iterations
        Sync::tick(10, function (int $id) use (&$count) {
            $count++;
            if ($count >= 3) {
                Sync::clear($id);
            }
        });

        $this->assertEquals(3, $count);
    }

    public function testAfterReturnsTimerId(): void
    {
        $timerId = Sync::after(1, function () {});
        $this->assertIsInt($timerId);
        $this->assertGreaterThan(0, $timerId);
    }

    public function testTickReturnsTimerId(): void
    {
        $count = 0;
        $timerId = Sync::tick(1, function (int $id) use (&$count) {
            $count++;
            if ($count >= 1) {
                Sync::clear($id);
            }
        });

        $this->assertIsInt($timerId);
        $this->assertGreaterThan(0, $timerId);
    }

    public function testTimerIdsAreUnique(): void
    {
        $ids = [];

        for ($i = 0; $i < 5; $i++) {
            $ids[] = Sync::after(1, function () {});
        }

        $this->assertCount(5, array_unique($ids));
    }

    public function testCallbackReceivesTimerId(): void
    {
        $receivedId = null;
        $count = 0;

        Sync::tick(1, function (int $id) use (&$receivedId, &$count) {
            $receivedId = $id;
            $count++;
            if ($count >= 1) {
                Sync::clear($id);
            }
        });

        $this->assertIsInt($receivedId);
        $this->assertGreaterThan(0, $receivedId);
    }
}
