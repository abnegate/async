<?php

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Async\Parallel\Adapter;

/**
 * Test class that extends Adapter to access protected methods
 */
class TestableParallelAdapter extends Adapter
{
    public static function run(callable $task, mixed ...$args): mixed
    {
        return $task(...$args);
    }

    public static function all(array $tasks): array
    {
        $results = [];
        foreach ($tasks as $task) {
            $results[] = $task();
        }
        return $results;
    }

    public static function map(array $items, callable $callback, ?int $workers = null): array
    {
        $results = [];
        foreach ($items as $index => $item) {
            $results[$index] = $callback($item, $index);
        }
        return $results;
    }

    public static function forEach(array $items, callable $callback, ?int $workers = null): void
    {
        foreach ($items as $index => $item) {
            $callback($item, $index);
        }
    }

    public static function pool(array $tasks, int $maxConcurrency): array
    {
        return static::all($tasks);
    }

    public static function isSupported(): bool
    {
        return true;
    }

    /**
     * Expose getCPUCount for testing
     */
    public static function exposeGetCPUCount(): int
    {
        return static::getCPUCount();
    }

    /**
     * Expose chunkItems for testing
     */
    public static function exposeChunkItems(array $items, ?int $workers = null): array
    {
        return static::chunkItems($items, $workers);
    }

    /**
     * Expose createMapWorker for testing
     */
    public static function exposeCreateMapWorker(): callable
    {
        return static::createMapWorker();
    }

    /**
     * Expose createForEachWorker for testing
     */
    public static function exposeCreateForEachWorker(): callable
    {
        return static::createForEachWorker();
    }

    /**
     * Reset CPU count cache for testing
     */
    public static function resetCPUCountCache(): void
    {
        $reflection = new \ReflectionClass(Adapter::class);
        $property = $reflection->getProperty('cpuCount');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }
}

class ParallelAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset CPU count cache before each test
        TestableParallelAdapter::resetCPUCountCache();
    }

    public function testGetCPUCount(): void
    {
        $cpuCount = TestableParallelAdapter::exposeGetCPUCount();

        $this->assertIsInt($cpuCount);
        $this->assertGreaterThanOrEqual(1, $cpuCount);
    }

    public function testGetCPUCountIsCached(): void
    {
        $firstCall = TestableParallelAdapter::exposeGetCPUCount();
        $secondCall = TestableParallelAdapter::exposeGetCPUCount();

        // Should return the same value (cached)
        $this->assertEquals($firstCall, $secondCall);
    }

    public function testChunkItemsWithEmptyArray(): void
    {
        $chunks = TestableParallelAdapter::exposeChunkItems([]);

        $this->assertEquals([], $chunks);
    }

    public function testChunkItemsWithSingleItem(): void
    {
        $chunks = TestableParallelAdapter::exposeChunkItems([1]);

        $this->assertCount(1, $chunks);
        $this->assertEquals([0 => 1], $chunks[0]);
    }

    public function testChunkItemsWithSpecificWorkers(): void
    {
        $items = [1, 2, 3, 4, 5, 6];

        // With 2 workers
        $chunks = TestableParallelAdapter::exposeChunkItems($items, 2);
        $this->assertCount(2, $chunks);

        // With 3 workers
        $chunks = TestableParallelAdapter::exposeChunkItems($items, 3);
        $this->assertCount(3, $chunks);

        // With 6 workers (one item per chunk)
        $chunks = TestableParallelAdapter::exposeChunkItems($items, 6);
        $this->assertCount(6, $chunks);
    }

    public function testChunkItemsWithMoreWorkersThanItems(): void
    {
        $items = [1, 2, 3];

        // 10 workers but only 3 items
        $chunks = TestableParallelAdapter::exposeChunkItems($items, 10);

        // Should have at most 3 chunks (one per item)
        $this->assertLessThanOrEqual(3, count($chunks));
    }

    public function testChunkItemsPreservesKeys(): void
    {
        $items = ['a' => 1, 'b' => 2, 'c' => 3];

        $chunks = TestableParallelAdapter::exposeChunkItems($items, 3);

        // Collect all keys from chunks
        $allKeys = [];
        foreach ($chunks as $chunk) {
            foreach (array_keys($chunk) as $key) {
                $allKeys[] = $key;
            }
        }

        $this->assertContains('a', $allKeys);
        $this->assertContains('b', $allKeys);
        $this->assertContains('c', $allKeys);
    }

    public function testChunkItemsWithNullWorkers(): void
    {
        $items = [1, 2, 3, 4, 5, 6, 7, 8];

        // Null workers should auto-detect CPU count
        $chunks = TestableParallelAdapter::exposeChunkItems($items, null);

        $this->assertNotEmpty($chunks);

        // Total items in all chunks should equal original
        $totalItems = 0;
        foreach ($chunks as $chunk) {
            $totalItems += count($chunk);
        }
        $this->assertEquals(count($items), $totalItems);
    }

    public function testCreateMapWorker(): void
    {
        $worker = TestableParallelAdapter::exposeCreateMapWorker();

        $this->assertIsCallable($worker);

        // Test the worker
        $chunk = [0 => 'a', 1 => 'b', 2 => 'c'];
        $callback = fn ($item, $index) => strtoupper($item) . $index;

        $results = $worker($chunk, $callback);

        $this->assertEquals([0 => 'A0', 1 => 'B1', 2 => 'C2'], $results);
    }

    public function testCreateMapWorkerPreservesKeys(): void
    {
        $worker = TestableParallelAdapter::exposeCreateMapWorker();

        $chunk = ['x' => 1, 'y' => 2, 'z' => 3];
        $callback = fn ($item, $index) => $item * 10;

        $results = $worker($chunk, $callback);

        $this->assertEquals(['x' => 10, 'y' => 20, 'z' => 30], $results);
    }

    public function testCreateForEachWorker(): void
    {
        $worker = TestableParallelAdapter::exposeCreateForEachWorker();

        $this->assertIsCallable($worker);

        // Test the worker
        $chunk = [0 => 'a', 1 => 'b', 2 => 'c'];
        $collected = [];
        $callback = function ($item, $index) use (&$collected) {
            $collected[] = "{$index}:{$item}";
        };

        $worker($chunk, $callback);

        $this->assertEquals(['0:a', '1:b', '2:c'], $collected);
    }

    public function testCreateForEachWorkerReturnsVoid(): void
    {
        $worker = TestableParallelAdapter::exposeCreateForEachWorker();

        $chunk = [1, 2, 3];
        $callback = fn ($item, $index) => null;

        $result = $worker($chunk, $callback);

        $this->assertNull($result);
    }

    public function testAdapterIsAbstract(): void
    {
        $reflection = new \ReflectionClass(Adapter::class);

        $this->assertTrue($reflection->isAbstract());
    }

    public function testAbstractMethods(): void
    {
        $reflection = new \ReflectionClass(Adapter::class);

        // Check abstract methods exist
        $this->assertTrue($reflection->getMethod('run')->isAbstract());
        $this->assertTrue($reflection->getMethod('all')->isAbstract());
        $this->assertTrue($reflection->getMethod('map')->isAbstract());
        $this->assertTrue($reflection->getMethod('forEach')->isAbstract());
        $this->assertTrue($reflection->getMethod('pool')->isAbstract());
    }

    public function testProtectedMethods(): void
    {
        $reflection = new \ReflectionClass(Adapter::class);

        // Check protected methods
        $this->assertTrue($reflection->getMethod('getCPUCount')->isProtected());
        $this->assertTrue($reflection->getMethod('chunkItems')->isProtected());
        $this->assertTrue($reflection->getMethod('createMapWorker')->isProtected());
        $this->assertTrue($reflection->getMethod('createForEachWorker')->isProtected());
    }

    public function testChunkItemsDistribution(): void
    {
        // Test that items are distributed evenly
        $items = range(1, 100);

        $chunks = TestableParallelAdapter::exposeChunkItems($items, 4);

        // With 4 workers and 100 items, each chunk should have ~25 items
        foreach ($chunks as $chunk) {
            // Allow some variance due to rounding
            $this->assertGreaterThanOrEqual(24, count($chunk));
            $this->assertLessThanOrEqual(26, count($chunk));
        }
    }

    public function testChunkItemsWithOneWorker(): void
    {
        $items = [1, 2, 3, 4, 5];

        $chunks = TestableParallelAdapter::exposeChunkItems($items, 1);

        // Should be one chunk with all items
        $this->assertCount(1, $chunks);
        $this->assertEquals([0 => 1, 1 => 2, 2 => 3, 3 => 4, 4 => 5], $chunks[0]);
    }
}
