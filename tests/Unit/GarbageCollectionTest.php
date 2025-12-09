<?php

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Async\GarbageCollection;
use Utopia\Async\Parallel\Configuration;

/**
 * Test class that uses the GarbageCollection trait
 */
class GarbageCollectionTestClass
{
    use GarbageCollection;

    /**
     * Expose the private method for testing
     */
    public function exposeTriggerGC(): void
    {
        $this->triggerGC();
    }
}

class GarbageCollectionTest extends TestCase
{
    public function testTraitCanBeUsed(): void
    {
        $instance = new GarbageCollectionTestClass();

        $this->assertInstanceOf(GarbageCollectionTestClass::class, $instance);
    }

    public function testTriggerGCCanBeCalled(): void
    {
        $instance = new GarbageCollectionTestClass();

        // Should not throw any exceptions
        $instance->exposeTriggerGC();

        $this->expectNotToPerformAssertions();
    }

    public function testTriggerGCCanBeCalledMultipleTimes(): void
    {
        $instance = new GarbageCollectionTestClass();

        // Multiple calls should work without issues
        for ($i = 0; $i < 100; $i++) {
            $instance->exposeTriggerGC();
        }

        $this->expectNotToPerformAssertions();
    }

    public function testMemoryThresholdConstantIsUsed(): void
    {
        // Verify the configuration value exists and has expected value (50MB)
        $this->assertEquals(52428800, Configuration::getMemoryThresholdForGc());
    }

    public function testTraitHasPrivateMethod(): void
    {
        $reflection = new \ReflectionClass(GarbageCollectionTestClass::class);
        $method = $reflection->getMethod('triggerGC');

        $this->assertTrue($method->isPrivate());
    }

    public function testTriggerGCIsLightweight(): void
    {
        $instance = new GarbageCollectionTestClass();

        // Measure time for many calls - should be fast since it only checks memory
        $start = microtime(true);
        for ($i = 0; $i < 1000; $i++) {
            $instance->exposeTriggerGC();
        }
        $elapsed = microtime(true) - $start;

        // 1000 calls should complete in under 100ms
        $this->assertLessThan(0.1, $elapsed);
    }
}
