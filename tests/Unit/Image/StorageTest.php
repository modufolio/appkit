<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Image;

use Modufolio\Appkit\Image\Storage;
use Modufolio\Appkit\Image\StorageInterface;
use Modufolio\Appkit\Image\File;
use PHPUnit\Framework\TestCase;

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
}
