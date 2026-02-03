<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Image;

/**
 * Custom exception for image processing errors
 *
 * Provides specific error context for image-related failures
 * to help with debugging and error handling.
 * @package   Image
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class ImageException extends \RuntimeException
{
    /**
     * Create exception for missing file
     */
    public static function fileNotFound(string $path): self
    {
        return new self("Image file not found: {$path}");
    }

    /**
     * Create exception for unreadable file
     */
    public static function fileNotReadable(string $path): self
    {
        return new self("Image file is not readable: {$path}");
    }

    /**
     * Create exception for invalid image type
     */
    public static function invalidImageType(string $path, string $type): self
    {
        return new self("Invalid or unsupported image type '{$type}' for file: {$path}");
    }

    /**
     * Create exception for MIME type mismatch
     */
    public static function mimeTypeMismatch(string $path, string $extension, string $mime): self
    {
        return new self(
            "MIME type mismatch for file: {$path}. " .
            "Extension '{$extension}' does not match MIME type '{$mime}'. " .
            "This could indicate a security risk or corrupted file."
        );
    }

    /**
     * Create exception for transformation errors
     */
    public static function transformationFailed(string $message, \Throwable $previous = null): self
    {
        return new self("Image transformation failed: {$message}", 0, $previous);
    }

    /**
     * Create exception for path traversal attempts
     */
    public static function pathTraversalAttempt(string $value): self
    {
        return new self("Path traversal detected in: {$value}");
    }
}
