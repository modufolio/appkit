<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Image;

/**
 * ImageJobService - Database adapter for PhotoLab image jobs
 *
 * This is a bridge adapter that allows using a database-backed
 * repository while maintaining compatibility with the JobStorageInterface.
 * The actual repository implementation is injected, removing direct
 * dependencies on specific ORM frameworks.
 *
 * @package   Image
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class ImageJobService implements JobStorageInterface
{
    public function __construct(
        private ImageJobRepositoryInterface $repository
    ) {
    }

    /**
     * Save a thumbnail generation job
     *
     * @param string $mediaRoot The media root directory path for storing job info
     */
    public function saveJob(
        string $mediaRoot,
        string $thumbName,
        array $options
    ): void {
        try {
            $this->repository->saveJob($mediaRoot, $thumbName, $options);
        } catch (\Throwable) {
            // Silently fail - thumbnail will be generated on-demand
        }
    }

    /**
     * Load a job by thumbnail name
     *
     * @param string $mediaRoot The media root directory path
     */
    public function loadJob(string $mediaRoot, string $thumbName): array|null
    {
        try {
            return $this->repository->loadJob($mediaRoot, $thumbName);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Delete a job by thumbnail name
     *
     * @param string $mediaRoot The media root directory path
     */
    public function deleteJob(string $mediaRoot, string $thumbName): bool
    {
        try {
            return $this->repository->deleteJob($mediaRoot, $thumbName);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if a job exists
     *
     * @param string $mediaRoot The media root directory path
     */
    public function jobExists(string $mediaRoot, string $thumbName): bool
    {
        try {
            return $this->repository->jobExists($mediaRoot, $thumbName);
        } catch (\Throwable) {
            return false;
        }
    }
}
