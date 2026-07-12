<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Image;

/**
 * Repository contract for persisting PhotoLab image jobs to a database.
 *
 * Implemented by the consuming application's ORM-backed repository and
 * injected into ImageJobService, which adapts it to JobStorageInterface.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface ImageJobRepositoryInterface
{
    /**
     * Save a thumbnail generation job.
     *
     * @param string $mediaRoot The media root directory path for storing job info
     */
    public function saveJob(
        string $mediaRoot,
        string $thumbName,
        array $options,
    ): void;

    /**
     * Load a job by thumbnail name.
     *
     * @param string $mediaRoot The media root directory path
     */
    public function loadJob(string $mediaRoot, string $thumbName): ?array;

    /**
     * Delete a job by thumbnail name.
     *
     * @param string $mediaRoot The media root directory path
     */
    public function deleteJob(string $mediaRoot, string $thumbName): bool;

    /**
     * Check if a job exists.
     *
     * @param string $mediaRoot The media root directory path
     */
    public function jobExists(string $mediaRoot, string $thumbName): bool;
}
