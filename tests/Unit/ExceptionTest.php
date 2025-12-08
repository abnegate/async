<?php

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Async\Exception;
use Utopia\Async\Exception\Adapter;
use Utopia\Async\Exception\Promise;
use Utopia\Async\Exception\Serialization;
use Utopia\Async\Exception\Timeout;

class ExceptionTest extends TestCase
{
    public function testExceptionToArray(): void
    {
        $exception = new \RuntimeException('Test error message', 42);

        $array = Exception::toArray($exception);

        $this->assertTrue($array['error']);
        $this->assertEquals(\RuntimeException::class, $array['class']);
        $this->assertEquals('Test error message', $array['message']);
        $this->assertEquals(42, $array['code']);
        $this->assertArrayHasKey('file', $array);
        $this->assertArrayHasKey('line', $array);
        $this->assertArrayHasKey('trace', $array);
    }

    public function testExceptionToArrayWithCustomException(): void
    {
        $exception = new Exception('Custom exception', 100);

        $array = Exception::toArray($exception);

        $this->assertTrue($array['error']);
        $this->assertEquals(Exception::class, $array['class']);
        $this->assertEquals('Custom exception', $array['message']);
        $this->assertEquals(100, $array['code']);
    }

    public function testIsErrorWithValidErrorArray(): void
    {
        $errorArray = [
            'error' => true,
            'class' => \RuntimeException::class,
            'message' => 'Error message',
            'code' => 0,
        ];

        $this->assertTrue(Exception::isError($errorArray));
    }

    public function testIsErrorWithInvalidData(): void
    {
        // Not an array
        $this->assertFalse(Exception::isError('string'));
        $this->assertFalse(Exception::isError(123));
        $this->assertFalse(Exception::isError(null));

        // Array without 'error' key
        $this->assertFalse(Exception::isError(['message' => 'test']));

        // Array with error = false
        $this->assertFalse(Exception::isError(['error' => false]));

        // Array with error as non-boolean
        $this->assertFalse(Exception::isError(['error' => 'true']));
        $this->assertFalse(Exception::isError(['error' => 1]));
    }

    public function testFromArrayWithValidException(): void
    {
        $errorArray = [
            'class' => \RuntimeException::class,
            'message' => 'Reconstructed error',
            'code' => 42,
        ];

        $exception = Exception::fromArray($errorArray);

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('Reconstructed error', $exception->getMessage());
        $this->assertEquals(42, $exception->getCode());
    }

    public function testFromArrayWithCustomExceptionClass(): void
    {
        $errorArray = [
            'class' => Exception::class,
            'message' => 'Custom exception',
            'code' => 10,
        ];

        $exception = Exception::fromArray($errorArray);

        $this->assertInstanceOf(Exception::class, $exception);
        $this->assertEquals('Custom exception', $exception->getMessage());
        $this->assertEquals(10, $exception->getCode());
    }

    public function testFromArrayWithNonExistentClass(): void
    {
        $errorArray = [
            'class' => 'NonExistentExceptionClass',
            'message' => 'Error message',
            'code' => 1,
        ];

        $exception = Exception::fromArray($errorArray);

        // Should fall back to RuntimeException
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('Error message', $exception->getMessage());
        $this->assertEquals(1, $exception->getCode());
    }

    public function testFromArrayWithNonThrowableClass(): void
    {
        $errorArray = [
            'class' => \stdClass::class,
            'message' => 'Error message',
            'code' => 1,
        ];

        $exception = Exception::fromArray($errorArray);

        // Should fall back to RuntimeException since stdClass is not Throwable
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testFromArrayWithMissingKeys(): void
    {
        // Missing class - should default to RuntimeException
        $exception = Exception::fromArray(['message' => 'test']);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('test', $exception->getMessage());

        // Missing message - should default to 'Unknown error'
        $exception = Exception::fromArray(['class' => \RuntimeException::class]);
        $this->assertEquals('Unknown error', $exception->getMessage());

        // Missing code - should default to 0
        $exception = Exception::fromArray(['class' => \RuntimeException::class, 'message' => 'test']);
        $this->assertEquals(0, $exception->getCode());

        // Empty array - all defaults
        $exception = Exception::fromArray([]);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('Unknown error', $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
    }

    public function testAdapterException(): void
    {
        $exception = new Adapter('Adapter error', 1);

        $this->assertInstanceOf(Exception::class, $exception);
        $this->assertInstanceOf(\Exception::class, $exception);
        $this->assertEquals('Adapter error', $exception->getMessage());
        $this->assertEquals(1, $exception->getCode());
    }

    public function testPromiseException(): void
    {
        $exception = new Promise('Promise error', 2);

        $this->assertInstanceOf(Exception::class, $exception);
        $this->assertInstanceOf(\Exception::class, $exception);
        $this->assertEquals('Promise error', $exception->getMessage());
        $this->assertEquals(2, $exception->getCode());
    }

    public function testSerializationException(): void
    {
        $exception = new Serialization('Serialization error', 3);

        $this->assertInstanceOf(Exception::class, $exception);
        $this->assertInstanceOf(\Exception::class, $exception);
        $this->assertEquals('Serialization error', $exception->getMessage());
        $this->assertEquals(3, $exception->getCode());
    }

    public function testTimeoutException(): void
    {
        $exception = new Timeout('Timeout error', 4);

        $this->assertInstanceOf(Exception::class, $exception);
        $this->assertInstanceOf(\Exception::class, $exception);
        $this->assertEquals('Timeout error', $exception->getMessage());
        $this->assertEquals(4, $exception->getCode());
    }

    public function testExceptionSerializationRoundTrip(): void
    {
        $original = new Exception('Original error', 99);
        $array = Exception::toArray($original);
        $reconstructed = Exception::fromArray($array);

        $this->assertInstanceOf(Exception::class, $reconstructed);
        $this->assertEquals($original->getMessage(), $reconstructed->getMessage());
        $this->assertEquals($original->getCode(), $reconstructed->getCode());
    }

    public function testSpecificExceptionSerializationRoundTrip(): void
    {
        // Test Adapter exception
        $adapter = new Adapter('Adapter error', 10);
        $array = Exception::toArray($adapter);
        $reconstructed = Exception::fromArray($array);
        $this->assertInstanceOf(Adapter::class, $reconstructed);

        // Test Promise exception
        $promise = new Promise('Promise error', 20);
        $array = Exception::toArray($promise);
        $reconstructed = Exception::fromArray($array);
        $this->assertInstanceOf(Promise::class, $reconstructed);

        // Test Serialization exception
        $serialization = new Serialization('Serialization error', 30);
        $array = Exception::toArray($serialization);
        $reconstructed = Exception::fromArray($array);
        $this->assertInstanceOf(Serialization::class, $reconstructed);

        // Test Timeout exception
        $timeout = new Timeout('Timeout error', 40);
        $array = Exception::toArray($timeout);
        $reconstructed = Exception::fromArray($array);
        $this->assertInstanceOf(Timeout::class, $reconstructed);
    }

    public function testBaseExceptionCanBeThrown(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Base exception');
        $this->expectExceptionCode(123);

        throw new Exception('Base exception', 123);
    }
}
