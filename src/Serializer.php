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
     * Serialize data using opis/closure for Closures and standard serialization for everything else.
     *
     * @param mixed $data
     * @return string
     */
    public static function serialize(mixed $data): string
    {
        if ($data instanceof \Closure) {
            return \Opis\Closure\serialize($data);
        }
        if (!\is_array($data) && !\is_object($data)) {
            return \serialize($data);
        }
        if (self::containsClosures($data)) {
            return \Opis\Closure\serialize($data);
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

        if (\str_contains($data, 'Opis\Closure\\')) {
            $result = @\Opis\Closure\unserialize($data, $options);
            if ($result !== false || $data === \Opis\Closure\serialize(false)) {
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
     * Memoization cache for closure detection
     *
     * @var array<int, bool>
     */
    private static array $closureCache = [];

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
            $objectId = \spl_object_id($data);

            if (isset(self::$closureCache[$objectId])) {
                return self::$closureCache[$objectId];
            }

            if (isset($visited[$objectId])) {
                return false;
            }

            $visited[$objectId] = true;

            $reflector = new \ReflectionObject($data);
            foreach ($reflector->getProperties() as $property) {
                $property->setAccessible(true);
                if ($property->isInitialized($data)) {
                    if (self::containsClosures($property->getValue($data), $depth - 1, $visited)) {
                        self::$closureCache[$objectId] = true;
                        return true;
                    }
                }
            }

            self::$closureCache[$objectId] = false;
        }

        return false;
    }

    /**
     * Clear the closure detection cache.
     * Call this periodically to prevent unbounded memory growth.
     *
     * @return void
     */
    public static function clearClosureCache(): void
    {
        self::$closureCache = [];
    }
}
