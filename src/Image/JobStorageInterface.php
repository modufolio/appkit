<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Image;

/**
 * Interface for image job storage and retrieval
 * Allows different persistence strategies (JSON, database, etc.)
 *
 * @package   Image
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface JobStorageInterface
{
    /**
     * Save a thumbnail generation job
     *
     * @param string $mediaRoot The media root directory path for storing job info
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
     */
    public function loadJob(string $mediaRoot, string $thumbName): array|null;

    /**
     * Delete a job by thumbnail name
     *
     * @param string $mediaRoot The media root directory path
     */
    public function deleteJob(string $mediaRoot, string $thumbName): bool;

    /**
     * Check if a job exists
     *
     * @param string $mediaRoot The media root directory path
     */
    public function jobExists(string $mediaRoot, string $thumbName): bool;
}
