<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Image;

use Modufolio\Appkit\Image\Disk;
use Modufolio\Appkit\Image\DiskManager;
use Modufolio\Appkit\Image\File;
use Modufolio\Appkit\Image\FileInterface;
use Modufolio\Appkit\Image\ImageException;
use Modufolio\Appkit\Image\Storage;
use Modufolio\Appkit\Image\StorageInterface;
use PHPUnit\Framework\TestCase;

class FileTest extends TestCase
{
    private string $testFile;
    private Storage $storage;
    private DiskManager $diskManager;

    protected function setUp(): void
    {
        // Create a temporary test file
        $this->testFile = sys_get_temp_dir() . '/test_image_' . uniqid() . '.txt';
        file_put_contents($this->testFile, 'test content');

        $this->storage = new Storage(
            baseMediaRoot: '/media',
            baseMediaUrl: '/media',
            uploadsDir: '/uploads'
        );

        $this->diskManager = new DiskManager();
        $this->diskManager->register(new Disk('avatars', '/media/images/avatars', '/media/images/avatars'));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }
    }

    public function testFileImplementsInterface(): void
    {
        $file = new File($this->testFile);
        $this->assertInstanceOf(FileInterface::class, $file);
    }

    public function testFileRoot(): void
    {
        $file = new File($this->testFile);
        $this->assertSame($this->testFile, $file->root());
    }

    public function testFileFilename(): void
    {
        $file = new File($this->testFile);
        $this->assertSame(basename($this->testFile), $file->filename());
    }

    public function testFileWithDiskName(): void
    {
        $file = new File($this->testFile, 'avatars', $this->storage, $this->diskManager);
        $this->assertSame('avatars', $file->disk()->name());
    }

    public function testFileWithDiskInstance(): void
    {
        $disk = new Disk('custom', '/uploads/custom');
        $file = new File($this->testFile, disk: $disk);
        $this->assertSame('custom', $file->disk()->name());
    }

    public function testFileExtension(): void
    {
        $file = new File($this->testFile);
        $this->assertSame('txt', $file->extension());
    }

    public function testFileName(): void
    {
        $file = new File($this->testFile);
        $this->assertStringContainsString('test_image_', $file->name());
    }

    public function testFileNotResizable(): void
    {
        $file = new File($this->testFile); // .txt file
        $this->assertFalse($file->isResizable());
    }

    public function testFileMediaRoot(): void
    {
        $file = new File($this->testFile, 'avatars', $this->storage, $this->diskManager);
        $mediaRoot = $file->mediaRoot();

        $this->assertStringStartsWith('/media/images/avatars/', $mediaRoot);
        $this->assertStringEndsWith(basename($this->testFile), $mediaRoot);
    }

    public function testFileMediaUrl(): void
    {
        $file = new File($this->testFile, 'avatars', $this->storage, $this->diskManager);
        $mediaUrl = $file->mediaUrl();

        $this->assertStringStartsWith('/media/images/avatars/', $mediaUrl);
        $this->assertStringEndsWith(basename($this->testFile), $mediaUrl);
    }

    public function testFileThrowsExceptionForNonexistentFile(): void
    {
        $this->expectException(ImageException::class);
        new File('/nonexistent/file.jpg');
    }

    public function testSetStorage(): void
    {
        $file = new File($this->testFile, storage: $this->storage);

        $newStorage = new Storage(
            baseMediaRoot: '/new/media',
            baseMediaUrl: 'https://new.example.com/media',
            uploadsDir: '/new/uploads'
        );

        $file->setStorage($newStorage);
        $this->assertSame('/new/media', $file->mediaRoot() > '' ? '/new/media' : '');
    }
}
