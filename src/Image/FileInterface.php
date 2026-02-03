<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Image;

/**
 * Interface for file operations
 *
 * @package   Image
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface FileInterface
{
    /**
     * Get the absolute file path
     */
    public function root(): string;

    /**
     * Get the filename with extension
     */
    public function filename(): string;

    /**
     * Get the storage disk for this file
     */
    public function disk(): DiskInterface;

    /**
     * Get the file extension
     */
    public function extension(): string;

    /**
     * Get the mime type
     */
    public function mime(): string|null;

    /**
     * Get the filename without extension
     */
    public function name(): string;

    /**
     * Check if file can be resized
     */
    public function isResizable(): bool;

    /**
     * Get the path relative to uploads directory
     *
     * The uploads directory is configured in StorageInterface.
     * If the file is not within the configured uploads directory,
     * this returns just the filename.
     */
    public function relativePathFromUploads(): string;

    /**
     * Get the storage path for media variants
     */
    public function mediaRoot(): string;

    /**
     * Get the URL for media variants
     */
    public function mediaUrl(): string;
}
