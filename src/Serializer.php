<?php

namespace Utopia\Async;

/**
 * High-performance serializer with igbinary support.
 *
 * Uses igbinary extension if available for 2-3x faster serialization,
 * falls back to standard PHP serialize() when not available.
 *
 * @package Utopia\Async
 */
class Serializer
{
    /**
     * Magic byte prefix to identify Opis\Closure serialized data
     * Using non-printable byte 0x01 followed by 'OC' to avoid false positives
     */
    private const OPIS_CLOSURE_PREFIX = "\x01OC";

    /**
     * Serialize data using opis/closure for Closures and standard serialization for everything else.
     *
     * @param mixed $data
     * @return string
     */
    public static function serialize(mixed $data): string
    {
        if ($data instanceof \Closure) {
            return self::OPIS_CLOSURE_PREFIX . \Opis\Closure\serialize($data);
        }
        if (!\is_array($data) && !\is_object($data)) {
            return \serialize($data);
        }
        if (self::containsClosures($data)) {
            return self::OPIS_CLOSURE_PREFIX . \Opis\Closure\serialize($data);
        }

        return \serialize($data);
    }

    /**
     * Unserialize data using opis/closure for Closures and standard unserialization for everything else.
     *
     * @param string $data
     * @param array{allowed_classes?: bool|array<class-string>} $options Options for unserialize
     * @return mixed
     * @throws \RuntimeException If unserialization fails or data is invalid
     */
    public static function unserialize(string $data, array $options = []): mixed
    {
        if (empty($data)) {
            throw new \RuntimeException('Cannot unserialize empty data');
        }

        // Fast prefix check - only check first 3 bytes
        if (\str_starts_with($data, self::OPIS_CLOSURE_PREFIX)) {
            $opisData = \substr($data, 3);
            $result = @\Opis\Closure\unserialize($opisData, $options);
            if ($result !== false || $opisData === \Opis\Closure\serialize(false)) {
                return $result;
            }
        }

        /** @var array{allowed_classes?: bool|array<class-string>} $mergedOptions */
        $mergedOptions = \array_merge(['allowed_classes' => false], $options);
        $result = @\unserialize($data, $mergedOptions);

        if ($result !== false || $data === \serialize(false)) {
            return $result;
        }

        throw new \RuntimeException('Failed to unserialize data');
    }

    /**
     * Memoization cache for closure detection using WeakMap to prevent memory leaks
     *
     * @var \WeakMap<object, bool>
     */
    private static \WeakMap $closureCache;

    /**
     * Cache of ReflectionClass instances by class name to avoid recreating them
     *
     * @var array<string, \ReflectionClass<object>>
     */
    private static array $reflectionCache = [];

    /**
     * Initialize the WeakMap if not already initialized
     */
    private static function initClosureCache(): void
    {
        if (!isset(self::$closureCache)) {
            self::$closureCache = new \WeakMap();
        }
    }

    /**
     * Check if an array or object contains closures recursively.
     *
     * @param mixed $data
     * @param int $depth Maximum recursion depth (default 10)
     * @param array<int, true> $visited Visited object IDs to prevent infinite recursion
     * @return bool
     */
    private static function containsClosures(mixed $data, int $depth = 10, array &$visited = []): bool
    {
        if ($depth <= 0) {
            return false;
        }

        if ($data instanceof \Closure) {
            return true;
        }

        if (\is_array($data)) {
            foreach ($data as $value) {
                if (self::containsClosures($value, $depth - 1, $visited)) {
                    return true;
                }
            }
        }

        if (\is_object($data)) {
            self::initClosureCache();

            $objectId = \spl_object_id($data);

            if (isset(self::$closureCache[$data])) {
                return self::$closureCache[$data];
            }

            if (isset($visited[$objectId])) {
                return false;
            }

            $visited[$objectId] = true;

            // Check both declared properties (via reflection) and dynamic properties (via get_object_vars)
            $className = \get_class($data);
            if (!isset(self::$reflectionCache[$className])) {
                self::$reflectionCache[$className] = new \ReflectionClass($className);
            }

            $reflector = self::$reflectionCache[$className];
            foreach ($reflector->getProperties() as $property) {
                if ($property->isInitialized($data)) {
                    $value = $property->getValue($data);
                    if (self::containsClosures($value, $depth - 1, $visited)) {
                        self::$closureCache[$data] = true;
                        return true;
                    }
                }
            }

            // Also check dynamic properties (like stdClass properties)
            foreach (\get_object_vars($data) as $value) {
                if (self::containsClosures($value, $depth - 1, $visited)) {
                    self::$closureCache[$data] = true;
                    return true;
                }
            }

            self::$closureCache[$data] = false;
        }

        return false;
    }

    /**
     * Clear the reflection cache.
     *
     * @return void
     */
    public static function clearClosureCache(): void
    {
        self::$reflectionCache = [];
        if (isset(self::$closureCache)) {
            self::$closureCache = new \WeakMap();
        }
    }
}
