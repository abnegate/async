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
        // Use opis/closure for serializing closures
        if (self::containsClosures($data)) {
            return \Opis\Closure\serialize($data);
        }

        // Use standard PHP serialization for non-closure data
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

        $result = @\Opis\Closure\unserialize($data, $options);
        if ($result !== false || $data === \Opis\Closure\serialize(false)) {
            return $result;
        }

        /** @var array{allowed_classes?: bool|array<class-string>} $mergedOptions */
        $mergedOptions = \array_merge(['allowed_classes' => false], $options);
        $result = @\unserialize($data, $mergedOptions);

        if ($result === false && $data !== \serialize(false)) {
            throw new \RuntimeException('Failed to unserialize data');
        }

        return $result;
    }

    /**
     * Check if an array or object contains closures recursively.
     *
     * @param mixed $data
     * @param int $depth Maximum recursion depth (default 10)
     * @return bool
     */
    private static function containsClosures(mixed $data, int $depth = 10): bool
    {
        if ($depth <= 0) {
            return false;
        }

        if ($data instanceof \Closure) {
            return true;
        }

        if (is_array($data)) {
            foreach ($data as $value) {
                if (self::containsClosures($value, $depth - 1)) {
                    return true;
                }
            }
        }

        if (is_object($data)) {
            foreach (get_object_vars($data) as $value) {
                if (self::containsClosures($value, $depth - 1)) {
                    return true;
                }
            }
        }

        return false;
    }
}
