<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Image;

/**
 * Interface for media storage configuration and path resolution
 *
 * @package   Image
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface StorageInterface
{
    /**
     * Get the base directory path for media storage
     */
    public function baseMediaRoot(): string;

    /**
     * Get the base URL for media access
     */
    public function baseMediaUrl(): string;

    /**
     * Get the uploads directory path
     *
     * Used for calculating relative paths from the source uploads directory.
     * Can be overridden if files are stored in a custom location.
     */
    public function uploadsDir(): string;

    /**
     * Resolve the full media path for a file
     */
    public function mediaRoot(FileInterface $file): string;

    /**
     * Resolve the full media URL for a file
     */
    public function mediaUrl(FileInterface $file): string;
}
