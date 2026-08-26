<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Image;

/**
 * Default storage implementation for media files
 * Handles path resolution using configurable base paths.
 *
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
        string $uploadsDir = '/uploads',
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
        return $this->baseMediaRoot.'/images/'.$file->disk()->name().'/'.$this->mediaHash($file).'/'.$file->filename();
    }

    public function mediaUrl(FileInterface $file): string
    {
        return $this->baseMediaUrl.'/images/'.$file->disk()->name().'/'.$this->mediaHash($file).'/'.$file->filename();
    }

    /**
     * The directory segment that namespaces a master's generated variants.
     *
     * Two properties matter here, and the previous md5($file->root()) had
     * neither:
     *
     *  - It hashes the uploads-relative path rather than the absolute one, so
     *    the same file yields the same URL whatever directory the project is
     *    installed in. Hashing the absolute path meant a move — or a deploy
     *    path differing from the developer's — silently changed every media
     *    URL on the site.
     *
     *  - It carries the master's modification time, so the URL changes when
     *    the bytes change. Masters are rewritten in place (downscaling and
     *    auto-orienting on upload), and without this a client or CDN holding
     *    the old response would keep serving the superseded image. With it,
     *    variants can be cached indefinitely.
     */
    private function mediaHash(FileInterface $file): string
    {
        $mtime = @filemtime($file->root()) ?: 0;

        return substr(md5($file->relativePathFromUploads()), 0, 10).'-'.$mtime;
    }
}
