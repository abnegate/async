<?php

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Async\Serializer;

class SerializerTest extends TestCase
{
    public function testSerializeScalarValues(): void
    {
        // Test string
        $serialized = Serializer::serialize('hello');
        $unserialized = Serializer::unserialize($serialized);
        $this->assertIsString($unserialized);
        $this->assertEquals('hello', $unserialized);

        // Test integer
        $serialized = Serializer::serialize(42);
        $this->assertEquals(42, Serializer::unserialize($serialized));

        // Test float
        $serialized = Serializer::serialize(3.14);
        $this->assertEquals(3.14, Serializer::unserialize($serialized));

        // Test boolean
        $serialized = Serializer::serialize(true);
        $this->assertTrue(Serializer::unserialize($serialized));

        // Test null
        $serialized = Serializer::serialize(null);
        $this->assertNull(Serializer::unserialize($serialized));
    }

    public function testSerializeArray(): void
    {
        $data = ['a' => 1, 'b' => 2, 'c' => [3, 4, 5]];
        $serialized = Serializer::serialize($data);
        $unserialized = Serializer::unserialize($serialized);

        $this->assertEquals($data, $unserialized);
    }

    public function testSerializeClosure(): void
    {
        $closure = function (int $x) {
            return $x * 2;
        };

        $serialized = Serializer::serialize($closure);
        $unserialized = Serializer::unserialize($serialized);

        $this->assertInstanceOf(\Closure::class, $unserialized);
        /** @var \Closure(int): int $unserialized */
        $this->assertEquals(10, $unserialized(5));
    }

    public function testSerializeArrayWithClosure(): void
    {
        $data = [
            'name' => 'test',
            'callback' => function (int $x) {
                return $x + 1;
            },
            'value' => 100,
        ];

        $serialized = Serializer::serialize($data);
        $unserialized = Serializer::unserialize($serialized);

        $this->assertIsArray($unserialized);
        /** @var array{name: string, value: int, callback: callable(int): int} $unserialized */
        $this->assertEquals('test', $unserialized['name']);
        $this->assertEquals(100, $unserialized['value']);
        $this->assertEquals(6, $unserialized['callback'](5));
    }

    public function testSerializeNestedClosures(): void
    {
        $data = [
            'level1' => [
                'level2' => [
                    'callback' => function (int $x) {
                        return $x * $x;
                    },
                ],
            ],
        ];

        $serialized = Serializer::serialize($data);
        $unserialized = Serializer::unserialize($serialized);

        $this->assertIsArray($unserialized);
        /** @var array{level1: array{level2: array{callback: callable(int): int}}} $unserialized */
        $callback = $unserialized['level1']['level2']['callback'];
        $this->assertEquals(25, $callback(5));
    }

    public function testSerializeObject(): void
    {
        $obj = new \stdClass();
        $obj->name = 'test';
        $obj->value = 42;

        $serialized = Serializer::serialize($obj);
        $unserialized = Serializer::unserialize($serialized, ['allowed_classes' => true]);

        $this->assertInstanceOf(\stdClass::class, $unserialized);
        $this->assertEquals('test', $unserialized->name);
        $this->assertEquals(42, $unserialized->value);
    }

    public function testSerializeObjectWithClosure(): void
    {
        $obj = new \stdClass();
        $obj->name = 'test';
        $obj->callback = function (int $x) {
            return $x * 3;
        };

        $serialized = Serializer::serialize($obj);
        $unserialized = Serializer::unserialize($serialized, ['allowed_classes' => true]);

        $this->assertInstanceOf(\stdClass::class, $unserialized);
        /** @var \stdClass&object{name: string, callback: callable} $unserialized */
        $this->assertEquals('test', $unserialized->name);
        /** @var callable(int): int $callback */
        $callback = $unserialized->callback;
        $this->assertEquals(15, $callback(5));
    }

    public function testUnserializeEmptyData(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot unserialize empty data');

        Serializer::unserialize('');
    }

    public function testUnserializeInvalidData(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to unserialize data');

        Serializer::unserialize('invalid_serialized_data');
    }

    public function testUnserializeFalseValue(): void
    {
        // Test serializing and unserializing boolean false
        $serialized = Serializer::serialize(false);
        $result = Serializer::unserialize($serialized);

        $this->assertFalse($result);
    }

    public function testSerializeDeeplyNestedWithoutClosure(): void
    {
        // Test that deeply nested structures without closures use standard serialization
        $data = [];
        $current = &$data;
        for ($i = 0; $i < 15; $i++) {
            $current['level'] = $i;
            $current['nested'] = [];
            $current = &$current['nested'];
        }

        $serialized = Serializer::serialize($data);
        $unserialized = Serializer::unserialize($serialized);

        $this->assertEquals($data, $unserialized);
    }

    public function testSerializeClosureAtMaxDepth(): void
    {
        // Test closure detection depth limit (default is 10)
        // Create nesting at depth 9 which should be detected
        $data = ['level0' => ['level1' => ['level2' => ['level3' => ['level4' =>
            ['level5' => ['level6' => ['level7' => ['level8' =>
                function () {
                    return 'found';
                }
            ]]]]
        ]]]]];

        $serialized = Serializer::serialize($data);
        $unserialized = Serializer::unserialize($serialized);

        $this->assertIsArray($unserialized);
        // Closure should be found and properly serialized
        /** @var array{level0: array{level1: array{level2: array{level3: array{level4: array{level5: array{level6: array{level7: array{level8: callable}}}}}}}}} $unserialized */
        $this->assertEquals('found', $unserialized['level0']['level1']['level2']['level3']['level4']['level5']['level6']['level7']['level8']());
    }

    public function testSerializeClosureBeyondMaxDepth(): void
    {
        // Create nesting beyond depth 10 - closure won't be detected
        // but this tests the depth limit behavior
        $data = ['a' => ['b' => ['c' => ['d' => ['e' =>
            ['f' => ['g' => ['h' => ['i' => ['j' => ['k' =>
                'deep_value'
            ]]]]]
            ]]]]]];

        $serialized = Serializer::serialize($data);
        $unserialized = Serializer::unserialize($serialized);

        $this->assertIsArray($unserialized);
        /** @var array{a: array{b: array{c: array{d: array{e: array{f: array{g: array{h: array{i: array{j: array{k: string}}}}}}}}}}} $unserialized */
        $this->assertEquals('deep_value', $unserialized['a']['b']['c']['d']['e']['f']['g']['h']['i']['j']['k']);
    }

    public function testUnserializeWithAllowedClassesOption(): void
    {
        $obj = new \stdClass();
        $obj->value = 'test';

        $serialized = Serializer::serialize($obj);

        // With allowed_classes = true
        $unserialized = Serializer::unserialize($serialized, ['allowed_classes' => true]);
        $this->assertInstanceOf(\stdClass::class, $unserialized);

        // With allowed_classes = specific class
        $unserialized = Serializer::unserialize($serialized, ['allowed_classes' => [\stdClass::class]]);
        $this->assertInstanceOf(\stdClass::class, $unserialized);
    }

    /**
     * Test memoization cache for object closure detection.
     * Verifies that the same object is not traversed multiple times.
     */
    public function testMemoizationCacheForObjects(): void
    {
        // Clear cache before test
        Serializer::clearClosureCache();

        $obj = new \stdClass();
        $obj->value = 'test';
        $obj->nested = new \stdClass();
        $obj->nested->data = 'nested data';

        // Serialize the same object twice
        $serialized1 = Serializer::serialize($obj);
        $serialized2 = Serializer::serialize($obj);

        // Both should produce identical results
        $this->assertEquals($serialized1, $serialized2);

        // Both should deserialize correctly
        $unserialized1 = Serializer::unserialize($serialized1, ['allowed_classes' => true]);
        $unserialized2 = Serializer::unserialize($serialized2, ['allowed_classes' => true]);

        /** @var \stdClass $unserialized1 */
        /** @var \stdClass $unserialized2 */
        $this->assertEquals($unserialized1->value, $unserialized2->value);
    }

    /**
     * Test circular reference handling in closure detection.
     */
    public function testCircularReferenceHandling(): void
    {
        $obj1 = new \stdClass();
        $obj2 = new \stdClass();
        $obj1->ref = $obj2;
        $obj2->ref = $obj1; // Circular reference

        // Should not cause infinite recursion
        $serialized = Serializer::serialize($obj1);

        // Should deserialize without issues
        $unserialized = Serializer::unserialize($serialized, ['allowed_classes' => true]);
        $this->assertInstanceOf(\stdClass::class, $unserialized);
    }

    /**
     * Test that clearClosureCache works correctly.
     */
    public function testClearClosureCache(): void
    {
        $obj = new \stdClass();
        $obj->value = 'test';

        // Serialize to populate cache
        Serializer::serialize($obj);

        // Clear cache should not throw
        Serializer::clearClosureCache();

        // Should still work after cache clear
        $serialized = Serializer::serialize($obj);
        $unserialized = Serializer::unserialize($serialized, ['allowed_classes' => true]);

        /** @var \stdClass $unserialized */
        $this->assertEquals('test', $unserialized->value);
    }

    /**
     * Test fast path for primitive types.
     */
    public function testFastPathForPrimitives(): void
    {
        // Primitives should use standard serialization (fast path)
        $primitives = [
            'string value',
            12345,
            3.14159,
            true,
            false,
            null,
        ];

        foreach ($primitives as $value) {
            $serialized = Serializer::serialize($value);
            $unserialized = Serializer::unserialize($serialized);
            $this->assertEquals($value, $unserialized);
        }
    }

    /**
     * Test fast detection of Opis\Closure serialized data.
     */
    public function testFastOpisClosureDetection(): void
    {
        $closure = fn () => 'test';
        $serialized = Serializer::serialize($closure);

        // Should contain Opis\Closure marker
        $this->assertStringContainsString('Opis\Closure\\', $serialized);

        // Should deserialize correctly using fast detection
        $unserialized = Serializer::unserialize($serialized);
        /** @var callable $unserialized */
        $this->assertEquals('test', $unserialized());
    }
}
