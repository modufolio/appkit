<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Image;

use Modufolio\Appkit\Image\DiskInterface;
use Modufolio\Appkit\Image\FileInterface;
use Modufolio\Appkit\Image\Storage;
use Modufolio\Appkit\Image\StorageInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Storage::class)]
class StorageTest extends TestCase
{
    private Storage $storage;

    protected function setUp(): void
    {
        $this->storage = new Storage(
            baseMediaRoot: '/var/www/media',
            baseMediaUrl: 'https://example.com/media',
            uploadsDir: '/var/www/uploads'
        );
    }

    public function testStorageImplementsInterface(): void
    {
        $this->assertInstanceOf(StorageInterface::class, $this->storage);
    }

    public function testBaseMediaRootConfiguration(): void
    {
        $this->assertSame('/var/www/media', $this->storage->baseMediaRoot());
    }

    public function testBaseMediaUrlConfiguration(): void
    {
        $this->assertSame('https://example.com/media', $this->storage->baseMediaUrl());
    }

    public function testUploadsDirConfiguration(): void
    {
        $this->assertSame('/var/www/uploads', $this->storage->uploadsDir());
    }

    public function testPathsAreTrimmed(): void
    {
        $storage = new Storage(
            baseMediaRoot: '/media/',
            baseMediaUrl: 'https://example.com/media/',
            uploadsDir: '/uploads/'
        );

        $this->assertSame('/media', $storage->baseMediaRoot());
        $this->assertSame('https://example.com/media', $storage->baseMediaUrl());
        $this->assertSame('/uploads', $storage->uploadsDir());
    }

    public function testDefaultPaths(): void
    {
        $storage = new Storage();

        $this->assertSame('/media', $storage->baseMediaRoot());
        $this->assertSame('/media', $storage->baseMediaUrl());
        $this->assertSame('/uploads', $storage->uploadsDir());
    }

    // ── variant directory naming ───────────────────────────────────

    /**
     * A master on disk, so the hash has a real modification time to read.
     * $relative is the path as it appears under the uploads directory, which
     * is what the hash is derived from.
     */
    private function file(string $absolute, string $relative, string $disk = 'tus'): FileInterface
    {
        $diskStub = new class ($disk) implements DiskInterface {
            public function __construct(private string $name) {}
            public function name(): string { return $this->name; }
            public function root(): string { return '/uploads/' . $this->name; }
            public function url(): string { return '/uploads/' . $this->name; }
            public function config(): array { return []; }
        };

        return new class ($absolute, $relative, $diskStub) implements FileInterface {
            public function __construct(
                private string $absolute,
                private string $relative,
                private DiskInterface $disk,
            ) {}
            public function root(): string { return $this->absolute; }
            public function filename(): string { return basename($this->absolute); }
            public function disk(): DiskInterface { return $this->disk; }
            public function extension(): string { return pathinfo($this->absolute, PATHINFO_EXTENSION); }
            public function mime(): string { return 'image/jpeg'; }
            public function name(): string { return pathinfo($this->absolute, PATHINFO_FILENAME); }
            public function isResizable(): bool { return true; }
            public function relativePathFromUploads(): string { return $this->relative; }
            public function mediaRoot(): string { return ''; }
            public function mediaUrl(): string { return ''; }
        };
    }

    /**
     * Write a master into its own directory, keeping the filename fixed so a
     * second copy elsewhere stands in for the same file under a different
     * install root.
     */
    private function makeMaster(string $name = 'photo.jpg'): string
    {
        $dir = sys_get_temp_dir() . '/appkit-storage-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $path = $dir . '/' . $name;
        file_put_contents($path, 'original bytes');

        return $path;
    }

    private function removeMaster(string $path): void
    {
        unlink($path);
        @rmdir(dirname($path));
    }

    public function testVariantRootAndUrlShareTheSameDirectory(): void
    {
        $master = $this->makeMaster();
        $file = $this->file($master, 'tus/abc12/photo.jpg');

        $root = $this->storage->mediaRoot($file);
        $url  = $this->storage->mediaUrl($file);

        // Same trailing path under each base — otherwise a generated variant
        // would be written somewhere the URL does not point.
        $this->assertSame(
            substr($root, strlen('/var/www/media')),
            substr($url, strlen('https://example.com/media')),
        );

        $this->removeMaster($master);
    }

    public function testHashDoesNotDependOnWhereTheProjectIsInstalled(): void
    {
        $here  = $this->makeMaster();
        $there = $this->makeMaster();
        // Same modification time, so only the absolute path differs.
        $mtime = filemtime($here);
        self::assertIsInt($mtime, 'The master fixture should exist and be readable.');
        touch($there, $mtime);

        // The same logical file, reached through two different install roots.
        $a = $this->file($here, 'tus/abc12/photo.jpg');
        $b = $this->file($there, 'tus/abc12/photo.jpg');

        $this->assertSame($this->storage->mediaUrl($a), $this->storage->mediaUrl($b));

        $this->removeMaster($here);
        $this->removeMaster($there);
    }

    public function testHashChangesWhenTheMasterIsRewritten(): void
    {
        $master = $this->makeMaster();
        $file = $this->file($master, 'tus/abc12/photo.jpg');

        $before = $this->storage->mediaUrl($file);

        // Masters are rewritten in place on upload (downscale / auto-orient);
        // the variant URL has to move with them or caches go stale.
        touch($master, filemtime($master) + 60);
        clearstatcache(true, $master);

        $this->assertNotSame($before, $this->storage->mediaUrl($file));

        $this->removeMaster($master);
    }

    public function testDifferentDisksDoNotShareADirectory(): void
    {
        $master = $this->makeMaster();

        $tus     = $this->file($master, 'tus/abc12/photo.jpg', 'tus');
        $default = $this->file($master, 'default/abc12/photo.jpg', 'default');

        $this->assertNotSame($this->storage->mediaUrl($tus), $this->storage->mediaUrl($default));

        $this->removeMaster($master);
    }
}
