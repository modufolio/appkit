<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Image;

/**
 * Default storage implementation for media files
 * Handles path resolution using configurable base paths
 *
 * @package   Image
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class Storage implements StorageInterface
{
    private string $baseMediaRoot;
    private string $baseMediaUrl;
    private string $uploadsDir;

    public function __construct(
        string $baseMediaRoot = '/media',
        string $baseMediaUrl = '/media',
        string $uploadsDir = '/uploads'
    ) {
        $this->baseMediaRoot = rtrim($baseMediaRoot, '/');
        $this->baseMediaUrl = rtrim($baseMediaUrl, '/');
        $this->uploadsDir = rtrim($uploadsDir, '/');
    }

    public function baseMediaRoot(): string
    {
        return $this->baseMediaRoot;
    }

    public function baseMediaUrl(): string
    {
        return $this->baseMediaUrl;
    }

    public function uploadsDir(): string
    {
        return $this->uploadsDir;
    }

    public function mediaRoot(FileInterface $file): string
    {
        $hash = md5($file->root());
        return $this->baseMediaRoot . '/images/' . $file->disk()->name() . '/' . $hash . '/' . $file->filename();
    }

    public function mediaUrl(FileInterface $file): string
    {
        $hash = md5($file->root());
        return $this->baseMediaUrl . '/images/' . $file->disk()->name() . '/' . $hash . '/' . $file->filename();
    }
}
