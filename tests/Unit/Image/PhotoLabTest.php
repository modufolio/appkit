<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Image;

use claviska\SimpleImage;
use Modufolio\Appkit\Image\Darkroom\GdLib;
use Modufolio\Appkit\Image\ImageProcessor;
use Modufolio\Appkit\Image\ImageVariant;
use Modufolio\Appkit\Image\JsonJobStorage;
use Modufolio\Appkit\Image\PhotoLab;
use Modufolio\Appkit\Image\Storage;
use Modufolio\Appkit\Image\Transformations\BlurTransformation;
use Modufolio\Appkit\Image\Transformations\ResizeTransformation;
use Modufolio\Appkit\Toolkit\Dir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
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
        $this->assertInstanceOf(ImageVariant::class, $variant);
        // the Darkroom's own key, so the filename carries a token and the
        // stored job is not silently ignored on regeneration
        $this->assertSame(['blur' => 5], $variant->modifications());
        $this->assertSame('photo-blur5.png', $variant->filename());

        // boolean intensity falls back to 10 pixels
        $variant = $this->imageLab()->blur(true);
        $this->assertInstanceOf(ImageVariant::class, $variant);
        $this->assertSame(['blur' => 10], $variant->modifications());
        $this->assertSame('photo-blur10.png', $variant->filename());
    }

    /**
     * End to end: the job PhotoLab records for a blurred variant must make the
     * Darkroom blur when a media route replays it. Before the fix the job said
     * `intensity`, which no driver reads, and the "blurred" file came back as a
     * plain resize.
     */
    #[RequiresPhpExtension('gd')]
    public function testBlurredVariantJobIsHonouredByTheDarkroom(): void
    {
        if (!class_exists(SimpleImage::class)) {
            self::markTestSkipped('claviska/simpleimage is not installed.');
        }

        copy(__DIR__.'/fixtures/image/cat.jpg', $original = $this->tmp.'/uploads/cat.jpg');

        $jobStorage = new JsonJobStorage();
        $lab = new PhotoLab($original, 'default', $this->storage, $jobStorage);

        $blurred = $lab->build()
            ->add(new ResizeTransformation(100))
            ->add(new BlurTransformation(5))
            ->process();
        $plain = $lab->resize(100);

        $this->assertInstanceOf(ImageVariant::class, $blurred);
        $this->assertInstanceOf(ImageVariant::class, $plain);
        $this->assertSame('cat-100x-blur5.jpg', $blurred->filename());
        $this->assertSame('cat-100x.jpg', $plain->filename());

        // Regenerate both the way a media route does: load the job, copy the
        // original into place and hand the job to the Darkroom verbatim.
        $darkroom = new GdLib();

        foreach ([$blurred, $plain] as $variant) {
            $job = $jobStorage->loadJob(dirname($variant->root()), $variant->filename());
            $this->assertNotNull($job);

            Dir::make(dirname($variant->root()));
            copy($original, $variant->root());
            $darkroom->process($variant->root(), $job);
        }

        $blurredJob = $jobStorage->loadJob(dirname($blurred->root()), $blurred->filename());
        $this->assertSame(5, $blurredJob['blur'] ?? null);

        // Same dimensions, different pixels: the blur was actually applied.
        $this->assertSame(
            array_slice(getimagesize($plain->root()) ?: [], 0, 2),
            array_slice(getimagesize($blurred->root()) ?: [], 0, 2)
        );
        $this->assertNotSame(
            file_get_contents($plain->root()),
            file_get_contents($blurred->root())
        );
    }

    public function testQualityWithRealImage(): void
    {
        $variant = $this->imageLab()->quality(70);

        $this->assertInstanceOf(ImageVariant::class, $variant);
        $this->assertSame('photo-q70.png', $variant->filename());
    }

    public function testGrayscaleWithRealImage(): void
    {
        foreach ([$this->imageLab()->grayscale(), $this->imageLab()->bw(), $this->imageLab()->greyscale()] as $variant) {
            $this->assertInstanceOf(ImageVariant::class, $variant);
            $this->assertSame(['grayscale' => true], $variant->modifications());
            $this->assertSame('photo-bw.png', $variant->filename());
        }
    }

    public function testSharpenWithRealImage(): void
    {
        $variant = $this->imageLab()->sharpen(75);

        $this->assertInstanceOf(ImageVariant::class, $variant);
        $this->assertSame(['sharpen' => 75], $variant->modifications());
        $this->assertSame('photo-sharpen75.png', $variant->filename());
    }

    public function testSharpenDefaultAmount(): void
    {
        $variant = $this->imageLab()->sharpen();

        $this->assertInstanceOf(ImageVariant::class, $variant);
        $this->assertSame(['sharpen' => 50], $variant->modifications());
        $this->assertSame('photo-sharpen50.png', $variant->filename());
    }

    public function testSrcsetWithIntegerSizes(): void
    {
        $srcset = $this->imageLab()->srcset([300, 600]);

        $this->assertNotNull($srcset);
        $this->assertStringContainsString('photo-300x.png 300w', $srcset);
        $this->assertStringContainsString('photo-600x.png 600w', $srcset);
        $this->assertStringContainsString(', ', $srcset);
    }

    public function testSrcsetWithStringConditions(): void
    {
        $srcset = $this->imageLab()->srcset([320 => '320w', 640 => '2x']);

        $this->assertNotNull($srcset);
        $this->assertStringContainsString('photo-320x.png 320w', $srcset);
        $this->assertStringContainsString('photo-640x.png 2x', $srcset);
    }

    public function testSrcsetWithArrayDefinitions(): void
    {
        $srcset = $this->imageLab()->srcset([
            ['width' => 480, 'condition' => '480w'],
        ]);

        $this->assertNotNull($srcset);
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
