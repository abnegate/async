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
}
