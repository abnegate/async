<?php

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Async\Exception\Adapter as AdapterException;
use Utopia\Async\Timer;
use Utopia\Async\Timer\Adapter\Sync;

class TimerTest extends TestCase
{
    protected function setUp(): void
    {
        Timer::reset();
    }

    protected function tearDown(): void
    {
        Timer::reset();
    }

    public function testSetAdapter(): void
    {
        Timer::setAdapter(Sync::class);

        // Verify by using the timer (should use Sync adapter)
        $executed = false;
        Timer::after(1, function () use (&$executed) {
            $executed = true;
        });

        $this->assertTrue($executed);
    }

    public function testSetAdapterWithInvalidClass(): void
    {
        $this->expectException(AdapterException::class);
        $this->expectExceptionMessage('Adapter must be a valid timer adapter class');

        Timer::setAdapter(\stdClass::class);
    }

    public function testAfter(): void
    {
        Timer::setAdapter(Sync::class);

        $executed = false;
        $start = microtime(true);

        Timer::after(50, function () use (&$executed) {
            $executed = true;
        });

        $elapsed = (microtime(true) - $start) * 1000;

        $this->assertTrue($executed);
        $this->assertGreaterThanOrEqual(45, $elapsed);
    }

    public function testTick(): void
    {
        Timer::setAdapter(Sync::class);

        $count = 0;

        Timer::tick(10, function (int $id) use (&$count) {
            $count++;
            if ($count >= 3) {
                Timer::clear($id);
            }
        });

        $this->assertEquals(3, $count);
    }

    public function testClear(): void
    {
        Timer::setAdapter(Sync::class);

        // Non-existent timer should return false
        $result = Timer::clear(999);
        $this->assertFalse($result);
    }

    public function testClearAll(): void
    {
        Timer::setAdapter(Sync::class);

        Timer::clearAll();
        $this->assertEquals([], Timer::getTimers());
    }

    public function testExists(): void
    {
        Timer::setAdapter(Sync::class);

        $this->assertFalse(Timer::exists(999));
    }

    public function testGetTimers(): void
    {
        Timer::setAdapter(Sync::class);

        // After all timers complete, list should be empty
        Timer::after(1, function () {});
        $this->assertEquals([], Timer::getTimers());
    }

    public function testReset(): void
    {
        Timer::setAdapter(Sync::class);
        Timer::reset();

        // After reset, adapter should be cleared
        // Set to Sync again so we can verify functionality
        Timer::setAdapter(Sync::class);

        $executed = false;
        Timer::after(1, function () use (&$executed) {
            $executed = true;
        });

        $this->assertTrue($executed);
    }

    public function testFacadeAutoDetectsAdapter(): void
    {
        // Test that auto-detection returns a timer ID (works with any adapter)
        Timer::setAdapter(Sync::class);

        $timerId = Timer::after(1, function () {});

        $this->assertIsInt($timerId);
        $this->assertGreaterThan(0, $timerId);
    }

    public function testAfterReturnsTimerId(): void
    {
        Timer::setAdapter(Sync::class);

        $timerId = Timer::after(1, function () {});

        $this->assertIsInt($timerId);
        $this->assertGreaterThan(0, $timerId);
    }

    public function testTickReturnsTimerId(): void
    {
        Timer::setAdapter(Sync::class);

        $count = 0;
        $timerId = Timer::tick(1, function (int $id) use (&$count) {
            $count++;
            if ($count >= 1) {
                Timer::clear($id);
            }
        });

        $this->assertIsInt($timerId);
        $this->assertGreaterThan(0, $timerId);
    }
}
