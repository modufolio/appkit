<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Image;

use Modufolio\Appkit\Toolkit\Mime;

/**
 * File representation for image processing
 *
 * @package   Image
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class File implements FileInterface
{
    protected string $filePath;
    protected string $filename;
    protected DiskInterface $disk;
    protected StorageInterface $storage;

    /**
     * @param string $filePath Absolute path to the file
     * @param DiskInterface|string $disk Disk instance or disk name
     * @param StorageInterface|null $storage Storage configuration
     * @param DiskManager|null $diskManager Disk manager for resolving disk names
     */
    public function __construct(
        string $filePath,
        DiskInterface|string $disk = 'default',
        ?StorageInterface $storage = null,
        ?DiskManager $diskManager = null
    ) {
        if (!file_exists($filePath)) {
            throw ImageException::fileNotFound($filePath);
        }

        if (!is_file($filePath)) {
            throw ImageException::invalidImageType($filePath, 'not a file');
        }

        if (!is_readable($filePath)) {
            throw ImageException::fileNotReadable($filePath);
        }

        $this->filePath = $filePath;
        $this->filename = basename($filePath);

        // Handle disk parameter - accept both DiskInterface and string
        if (is_string($disk)) {
            // Resolve disk name using DiskManager
            $diskManager ??= new DiskManager();
            $this->disk = $diskManager->disk($disk);
        } else {
            $this->disk = $disk;
        }

        $this->storage = $storage ?? new Storage();
    }

    public function root(): string
    {
        return $this->filePath;
    }

    public function setStorage(StorageInterface $storage): void
    {
        $this->storage = $storage;
    }

    public function filename(): string
    {
        return $this->filename;
    }

    public function disk(): DiskInterface
    {
        return $this->disk;
    }

    public function extension(): string
    {
        return pathinfo($this->filename, PATHINFO_EXTENSION);
    }

    /**
     * Detects the mime type of the file
     */
    public function mime(): string|null
    {
        return Mime::type($this->root());
    }

    public function name(): string
    {
        return pathinfo($this->filename, PATHINFO_FILENAME);
    }

    public function isResizable(): bool
    {
        return in_array(strtolower($this->extension()), ['jpg', 'jpeg', 'png', 'webp', 'gif']);
    }

    /**
     * Get the path relative to the uploads directory
     * For example: "users/valley.webp" for a file in uploads/users/
     *
     * Uses the uploads directory configured in the Storage instance.
     * If the file is not within the uploads directory, returns the filename.
     */
    public function relativePathFromUploads(): string
    {
        $uploadsDir = rtrim($this->storage->uploadsDir(), '/') . '/';
        if (str_starts_with($this->filePath, $uploadsDir)) {
            return substr($this->filePath, strlen($uploadsDir));
        }
        // Fallback to just filename if not in uploads directory
        return $this->filename;
    }

    public function mediaRoot(): string
    {
        return $this->storage->mediaRoot($this);
    }

    public function mediaUrl(): string
    {
        return $this->storage->mediaUrl($this);
    }
}