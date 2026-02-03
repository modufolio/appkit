<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Image;

/**
 * Repository interface for image job persistence
 *
 * This interface decouples the image package from specific implementations.
 * Implementations can use Doctrine, Eloquent, MongoDB, or any other persistence layer.
 *
 * @package   Image
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface ImageJobRepositoryInterface
{
    /**
     * Save a thumbnail generation job
     *
     * @param string $mediaRoot The media root directory path
     * @param string $thumbName The thumbnail filename
     * @param array $options The transformation options
     */
    public function saveJob(
        string $mediaRoot,
        string $thumbName,
        array $options
    ): void;

    /**
     * Load a job by thumbnail name
     *
     * @param string $mediaRoot The media root directory path
     * @param string $thumbName The thumbnail filename
     * @return array|null Job data as associative array, or null if not found
     */
    public function loadJob(string $mediaRoot, string $thumbName): array|null;

    /**
     * Delete a job by thumbnail name
     *
     * @param string $mediaRoot The media root directory path
     * @param string $thumbName The thumbnail filename
     * @return bool True if deleted, false otherwise
     */
    public function deleteJob(string $mediaRoot, string $thumbName): bool;

    /**
     * Check if a job exists
     *
     * @param string $mediaRoot The media root directory path
     * @param string $thumbName The thumbnail filename
     * @return bool True if exists, false otherwise
     */
    public function jobExists(string $mediaRoot, string $thumbName): bool;
}
