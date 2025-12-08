<?php

namespace Utopia\Async;

/**
 * Base exception for all library exceptions.
 *
 * @package Utopia\Async\Exception
 */
class Exception extends \Exception
{
    /**
     * Convert any Throwable to a serializable array.
     *
     * @param \Throwable $throwable The throwable to convert
     * @return array<string, mixed> Serializable error data
     */
    public static function toArray(\Throwable $throwable): array
    {
        return [
            'error' => true,
            'class' => $throwable::class,
            'message' => $throwable->getMessage(),
            'code' => $throwable->getCode(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'trace' => $throwable->getTraceAsString(),
        ];
    }

    /**
     * Check if an array contains error data.
     *
     * @param mixed $data Data to check
     * @return bool True if the data is an error array
     */
    public static function isError(mixed $data): bool
    {
        return \is_array($data)
            && isset($data['error'])
            && $data['error'] === true;
    }

    /**
     * Reconstruct an exception from serialized error data.
     *
     * Safely reconstructs an exception from error data that has been passed
     * between processes or threads. If the original exception class doesn't
     * exist or isn't a Throwable, falls back to RuntimeException.
     *
     * @param array<string, mixed> $error Error data array with keys:
     *   - 'class': The exception class name
     *   - 'message': The error message
     *   - 'code': The error code (optional)
     * @return \Throwable The reconstructed exception
     */
    public static function fromArray(array $error): \Throwable
    {
        $class = $error['class'] ?? \RuntimeException::class;
        $message = $error['message'] ?? 'Unknown error';
        $code = $error['code'] ?? 0;

        if (\is_string($class) && \class_exists($class) && \is_subclass_of($class, \Throwable::class)) {
            return new $class(\is_string($message) ? $message : 'Unknown error', \is_int($code) ? $code : 0);
        }

        return new \RuntimeException(\is_string($message) ? $message : 'Unknown error', \is_int($code) ? $code : 0);
    }
}
