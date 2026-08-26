<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Image;

use Modufolio\Appkit\Image\DiskManager;
use Modufolio\Appkit\Image\File;
use Modufolio\Appkit\Image\ImageException;
use Modufolio\Appkit\Image\ImageProcessor;
use Modufolio\Appkit\Image\ImageVariant;
use Modufolio\Appkit\Image\JsonJobStorage;
use Modufolio\Appkit\Image\Storage;
use Modufolio\Appkit\Image\Transformations\BlurTransformation;
use Modufolio\Appkit\Image\Transformations\CropTransformation;
use Modufolio\Appkit\Image\Transformations\QualityTransformation;
use Modufolio\Appkit\Image\Transformations\ResizeTransformation;
use Modufolio\Appkit\Toolkit\Dir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImageProcessor::class)]
class ImageProcessorTest extends TestCase
{
    private string $tmp;
    private string $testFile;
    private string $testImage;
    private Storage $storage;
    private DiskManager $diskManager;
    private JsonJobStorage $jobStorage;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/appkit-'.uniqid();
        mkdir($this->tmp.'/uploads', 0o777, true);
        mkdir($this->tmp.'/media', 0o777, true);

        $this->testFile = $this->tmp.'/uploads/test_image.txt';
        file_put_contents($this->testFile, 'test');

        $this->testImage = $this->tmp.'/uploads/test-image.png';
        $image = imagecreatetruecolor(20, 20);
        imagepng($image, $this->testImage);
        imagedestroy($image);

        $this->storage = new Storage(
            baseMediaRoot: $this->tmp.'/media',
            baseMediaUrl: '/media',
            uploadsDir: $this->tmp.'/uploads'
        );

        $this->diskManager = new DiskManager();
        $this->jobStorage = new JsonJobStorage();
    }

    protected function tearDown(): void
    {
        Dir::remove($this->tmp);
    }

    public function testProcessorCanAddTransformation(): void
    {
        $file = new File($this->testFile, 'default', $this->storage, $this->diskManager);
        $processor = new ImageProcessor($file, $this->jobStorage);

        $processor->add(new CropTransformation(300, 200));

        $this->assertCount(1, $processor->getTransformationNames());
    }

    public function testProcessorCanAddMultipleTransformations(): void
    {
        $file = new File($this->testFile, 'default', $this->storage, $this->diskManager);
        $processor = new ImageProcessor($file, $this->jobStorage);

        $processor->add(new CropTransformation(300, 200))
                  ->add(new BlurTransformation(10))
                  ->add(new QualityTransformation(90));

        $this->assertCount(3, $processor->getTransformationNames());
    }

    public function testProcessorReturnsTransformationNames(): void
    {
        $file = new File($this->testFile, 'default', $this->storage, $this->diskManager);
        $processor = new ImageProcessor($file, $this->jobStorage);

        $processor->add(new CropTransformation(300, 200))
                  ->add(new BlurTransformation(10));

        $names = $processor->getTransformationNames();

        $this->assertContains('crop', $names);
        $this->assertContains('blur', $names);
    }

    public function testProcessorReturnsConfigurations(): void
    {
        $file = new File($this->testFile, 'default', $this->storage, $this->diskManager);
        $processor = new ImageProcessor($file, $this->jobStorage);

        $processor->add(new CropTransformation(300, 200))
                  ->add(new QualityTransformation(90));

        $configs = $processor->getConfigurations();

        $this->assertCount(2, $configs);
        $this->assertSame('crop', $configs[0]['name']);
        $this->assertSame('quality', $configs[1]['name']);
    }

    public function testProcessorCanClear(): void
    {
        $file = new File($this->testFile, 'default', $this->storage, $this->diskManager);
        $processor = new ImageProcessor($file, $this->jobStorage);

        $processor->add(new CropTransformation(300, 200))
                  ->add(new BlurTransformation(10));

        $stacked = $processor->getTransformationNames();

        $this->assertCount(2, $stacked);

        $processor->clear();

        $cleared = $processor->getTransformationNames();

        $this->assertCount(0, $cleared);
    }

    public function testProcessorIsFluentInterface(): void
    {
        $file = new File($this->testFile, 'default', $this->storage, $this->diskManager);
        $processor = new ImageProcessor($file, $this->jobStorage);

        $result = $processor->add(new CropTransformation(300, 200));

        $this->assertInstanceOf(ImageProcessor::class, $result);
    }

    public function testProcessReturnsFileForNonResizableFile(): void
    {
        $file = new File($this->testFile, 'default', $this->storage, $this->diskManager);
        $processor = new ImageProcessor($file, $this->jobStorage);

        $this->assertSame($file, $processor->process());
    }

    public function testProcessThrowsWhenSourceFileDisappears(): void
    {
        $file = new File($this->testImage, 'default', $this->storage, $this->diskManager);
        $processor = new ImageProcessor($file, $this->jobStorage);

        unlink($this->testImage);

        $this->expectException(ImageException::class);
        $this->expectExceptionMessage('Image file not found');

        $processor->process();
    }

    public function testProcessThrowsWhenSourceFileNotReadable(): void
    {
        $file = new File($this->testImage, 'default', $this->storage, $this->diskManager);
        $processor = new ImageProcessor($file, $this->jobStorage);

        chmod($this->testImage, 0o000);

        try {
            $this->expectException(ImageException::class);
            $this->expectExceptionMessage('not readable');

            $processor->process();
        } finally {
            chmod($this->testImage, 0o644);
        }
    }

    public function testProcessWithoutTransformationsServesOriginal(): void
    {
        $file = new File($this->testImage, 'default', $this->storage, $this->diskManager);
        $processor = new ImageProcessor($file, $this->jobStorage);

        $variant = $processor->process();

        $this->assertInstanceOf(ImageVariant::class, $variant);
        $this->assertSame('test-image.png', $variant->filename());
        $this->assertSame([], $variant->modifications());
        $this->assertSame($file, $variant->original());

        // job was saved for the original file
        $mediaRoot = dirname($file->mediaRoot());
        $job = $this->jobStorage->loadJob($mediaRoot, 'test-image.png');

        $this->assertSame([
            'filename' => 'test-image.png',
            'transformations' => [],
        ], $job);
    }

    public function testProcessWithoutTransformationsSkipsJobWhenThumbExists(): void
    {
        $file = new File($this->testImage, 'default', $this->storage, $this->diskManager);
        $processor = new ImageProcessor($file, $this->jobStorage);

        // pre-create the thumb at its media location
        $mediaRoot = dirname($file->mediaRoot());
        mkdir($mediaRoot, 0o777, true);
        copy($this->testImage, $mediaRoot.'/test-image.png');

        $variant = $processor->process();

        $this->assertInstanceOf(ImageVariant::class, $variant);
        $this->assertTrue($variant->exists());
        $this->assertFalse($this->jobStorage->jobExists($mediaRoot, 'test-image.png'));
    }

    public function testProcessWithTransformationsCreatesVariantAndJob(): void
    {
        $file = new File($this->testImage, 'default', $this->storage, $this->diskManager);
        $processor = new ImageProcessor($file, $this->jobStorage);

        $variant = $processor
            ->add(new ResizeTransformation(300, 200))
            ->add(new QualityTransformation(80))
            ->process();

        $this->assertInstanceOf(ImageVariant::class, $variant);
        $this->assertSame('test-image-300x200-q80.png', $variant->filename());
        $this->assertSame(
            ['width' => 300, 'height' => 200, 'quality' => 80],
            $variant->modifications()
        );
        $this->assertStringStartsWith('/media/images/default/', $variant->url());

        $mediaRoot = dirname($file->mediaRoot());
        $job = $this->jobStorage->loadJob($mediaRoot, 'test-image-300x200-q80.png');

        $this->assertNotNull($job);
        $this->assertSame(300, $job['width']);
        $this->assertSame(80, $job['quality']);
        $this->assertSame('test-image.png', $job['filename']);
        $this->assertSame(['resize', 'quality'], $job['transformations']);
    }

    public function testProcessWithTransformationsSkipsJobWhenThumbExists(): void
    {
        $file = new File($this->testImage, 'default', $this->storage, $this->diskManager);
        $processor = new ImageProcessor($file, $this->jobStorage);
        $processor->add(new ResizeTransformation(100));

        $mediaRoot = dirname($file->mediaRoot());
        mkdir($mediaRoot, 0o777, true);
        copy($this->testImage, $mediaRoot.'/test-image-100x.png');

        $variant = $processor->process();

        $this->assertInstanceOf(ImageVariant::class, $variant);
        $this->assertSame('test-image-100x.png', $variant->filename());
        $this->assertFalse($this->jobStorage->jobExists($mediaRoot, 'test-image-100x.png'));
    }
}
