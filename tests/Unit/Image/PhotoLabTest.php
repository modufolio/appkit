<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Image;

use Modufolio\Appkit\Image\ImageProcessor;
use Modufolio\Appkit\Image\ImageVariant;
use Modufolio\Appkit\Image\JsonJobStorage;
use Modufolio\Appkit\Image\PhotoLab;
use Modufolio\Appkit\Image\Storage;
use Modufolio\Appkit\Toolkit\Dir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhotoLab::class)]
class PhotoLabTest extends TestCase
{
    private string $tmp;
    private string $testFile;
    private string $testImage;
    private Storage $storage;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/appkit-'.uniqid();
        mkdir($this->tmp.'/uploads', 0o777, true);
        mkdir($this->tmp.'/media', 0o777, true);

        $this->testFile = $this->tmp.'/uploads/test_image.txt';
        file_put_contents($this->testFile, 'test');

        $this->testImage = $this->tmp.'/uploads/photo.png';
        $image = imagecreatetruecolor(40, 30);
        imagepng($image, $this->testImage);
        imagedestroy($image);

        $this->storage = new Storage(
            baseMediaRoot: $this->tmp.'/media',
            baseMediaUrl: '/media',
            uploadsDir: $this->tmp.'/uploads'
        );
    }

    protected function tearDown(): void
    {
        Dir::remove($this->tmp);
    }

    private function imageLab(): PhotoLab
    {
        return new PhotoLab(
            absolutePath: $this->testImage,
            disk: 'default',
            storage: $this->storage,
            jobStorage: new JsonJobStorage()
        );
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

        $this->assertInstanceOf(ImageProcessor::class, $processor);
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

    public function testResizeWithRealImage(): void
    {
        $variant = $this->imageLab()->resize(300, 200, 80);

        $this->assertInstanceOf(ImageVariant::class, $variant);
        $this->assertSame('photo-300x200-q80.png', $variant->filename());
    }

    public function testCropWithRealImage(): void
    {
        $variant = $this->imageLab()->crop(100, 100);

        $this->assertInstanceOf(ImageVariant::class, $variant);
        $this->assertSame('photo-100x100-crop.png', $variant->filename());
    }

    public function testBlurWithRealImage(): void
    {
        $variant = $this->imageLab()->blur(5);
        $this->assertSame(['intensity' => 5], $variant->modifications());

        // boolean intensity falls back to 10 pixels
        $variant = $this->imageLab()->blur(true);
        $this->assertSame(['intensity' => 10], $variant->modifications());
    }

    public function testQualityWithRealImage(): void
    {
        $variant = $this->imageLab()->quality(70);

        $this->assertInstanceOf(ImageVariant::class, $variant);
        $this->assertSame('photo-q70.png', $variant->filename());
    }

    public function testGrayscaleWithRealImage(): void
    {
        $this->assertInstanceOf(ImageVariant::class, $this->imageLab()->grayscale());
        $this->assertInstanceOf(ImageVariant::class, $this->imageLab()->bw());
        $this->assertInstanceOf(ImageVariant::class, $this->imageLab()->greyscale());
    }

    public function testSharpenWithRealImage(): void
    {
        $variant = $this->imageLab()->sharpen(75);

        $this->assertInstanceOf(ImageVariant::class, $variant);
        $this->assertSame(['amount' => 75], $variant->modifications());
    }

    public function testSharpenDefaultAmount(): void
    {
        $variant = $this->imageLab()->sharpen();

        $this->assertSame(['amount' => 50], $variant->modifications());
    }

    public function testSrcsetWithIntegerSizes(): void
    {
        $srcset = $this->imageLab()->srcset([300, 600]);

        $this->assertStringContainsString('photo-300x.png 300w', $srcset);
        $this->assertStringContainsString('photo-600x.png 600w', $srcset);
        $this->assertStringContainsString(', ', $srcset);
    }

    public function testSrcsetWithStringConditions(): void
    {
        $srcset = $this->imageLab()->srcset([320 => '320w', 640 => '2x']);

        $this->assertStringContainsString('photo-320x.png 320w', $srcset);
        $this->assertStringContainsString('photo-640x.png 2x', $srcset);
    }

    public function testSrcsetWithArrayDefinitions(): void
    {
        $srcset = $this->imageLab()->srcset([
            ['width' => 480, 'condition' => '480w'],
        ]);

        $this->assertStringContainsString('photo-480x.png 480w', $srcset);
    }

    public function testSrcsetWithInvalidSizes(): void
    {
        $lab = $this->imageLab();

        $this->assertNull($lab->srcset());
        $this->assertNull($lab->srcset([]));
        $this->assertNull($lab->srcset('300w'));
    }
}
