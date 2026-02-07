<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Image;

use Modufolio\Appkit\Image\DiskManager;
use Modufolio\Appkit\Image\JsonJobStorage;
use Modufolio\Appkit\Image\PhotoLab;
use Modufolio\Appkit\Image\Storage;
use PHPUnit\Framework\TestCase;

class PhotoLabTest extends TestCase
{
    private string $testFile;
    private Storage $storage;
    private DiskManager $diskManager;

    protected function setUp(): void
    {
        $this->testFile = sys_get_temp_dir() . '/test_image_' . uniqid() . '.txt';
        file_put_contents($this->testFile, 'test');

        $this->storage = new Storage(
            baseMediaRoot: '/media',
            baseMediaUrl: '/media',
            uploadsDir: '/uploads'
        );

        $this->diskManager = new DiskManager();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }
    }

    public function testPhotoLabCreation(): void
    {
        $photoLab = new PhotoLab(
            $this->testFile,
            'default',
            $this->storage,
            new JsonJobStorage()
        );

        $this->assertInstanceOf(PhotoLab::class, $photoLab);
    }

    public function testPhotoLabBuildReturnsProcessor(): void
    {
        $photoLab = new PhotoLab(
            $this->testFile,
            'default',
            $this->storage,
            new JsonJobStorage()
        );

        $processor = $photoLab->build();

        $this->assertNotNull($processor);
    }

    public function testPhotoLabWithNonexistentFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PhotoLab(
            absolutePath: '/nonexistent/file.jpg',
            disk: 'default',
            storage: $this->storage,
            jobStorage: new JsonJobStorage()
        );
    }

    public function testPhotoLabConvenienceMethodResize(): void
    {
        $photoLab = new PhotoLab(
            absolutePath: $this->testFile,
            disk: 'default',
            storage: $this->storage,
            jobStorage: new JsonJobStorage()
        );

        // File is not an image, so it should return the file itself
        $result = $photoLab->resize(300, 200);

        $this->assertNotNull($result);
    }

    public function testPhotoLabConvenienceMethodCrop(): void
    {
        $photoLab = new PhotoLab(
            absolutePath: $this->testFile,
            disk: 'default',
            storage: $this->storage,
            jobStorage: new JsonJobStorage()
        );

        $result = $photoLab->crop(300, 200);

        $this->assertNotNull($result);
    }

    public function testPhotoLabConvenienceMethodBlur(): void
    {
        $photoLab = new PhotoLab(
            absolutePath: $this->testFile,
            disk: 'default',
            storage: $this->storage,
            jobStorage: new JsonJobStorage()
        );

        $result = $photoLab->blur(10);

        $this->assertNotNull($result);
    }

    public function testPhotoLabConvenienceMethodQuality(): void
    {
        $photoLab = new PhotoLab(
            absolutePath: $this->testFile,
            disk: 'default',
            storage: $this->storage,
            jobStorage: new JsonJobStorage()
        );

        $result = $photoLab->quality(90);

        $this->assertNotNull($result);
    }

    public function testPhotoLabConvenienceMethodGrayscale(): void
    {
        $photoLab = new PhotoLab(
            absolutePath: $this->testFile,
            disk: 'default',
            storage: $this->storage,
            jobStorage: new JsonJobStorage()
        );

        $result = $photoLab->grayscale();

        $this->assertNotNull($result);
    }

    public function testPhotoLabBwAlias(): void
    {
        $photoLab = new PhotoLab(
            absolutePath: $this->testFile,
            disk: 'default',
            storage: $this->storage,
            jobStorage: new JsonJobStorage()
        );

        $result = $photoLab->bw();

        $this->assertNotNull($result);
    }

    public function testPhotoLabGreyscaleAlias(): void
    {
        $photoLab = new PhotoLab(
            absolutePath: $this->testFile,
            disk: 'default',
            storage: $this->storage,
            jobStorage: new JsonJobStorage()
        );

        $result = $photoLab->greyscale();

        $this->assertNotNull($result);
    }
}
