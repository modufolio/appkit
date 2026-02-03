<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Image;

use Modufolio\Appkit\Image\DiskManager;
use Modufolio\Appkit\Image\File;
use Modufolio\Appkit\Image\ImageProcessor;
use Modufolio\Appkit\Image\JsonJobStorage;
use Modufolio\Appkit\Image\Storage;
use Modufolio\Appkit\Image\Transformations\BlurTransformation;
use Modufolio\Appkit\Image\Transformations\CropTransformation;
use Modufolio\Appkit\Image\Transformations\QualityTransformation;
use PHPUnit\Framework\TestCase;

class ImageProcessorTest extends TestCase
{
    private string $testFile;
    private Storage $storage;
    private DiskManager $diskManager;
    private JsonJobStorage $jobStorage;

    protected function setUp(): void
    {
        $this->testFile = sys_get_temp_dir() . '/test_image_' . uniqid() . '.txt';
        file_put_contents($this->testFile, 'test');

        $this->storage = new Storage(
            baseMediaRoot: '/media',
            baseMediaUrl: '/media'
        );

        $this->diskManager = new DiskManager();
        $this->jobStorage = new JsonJobStorage();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }
    }

    public function testProcessorCanAddTransformation(): void
    {
        $file = new File($this->testFile, 'default', $this->storage, $this->diskManager);
        $processor = new ImageProcessor($file, $this->storage, $this->jobStorage);

        $processor->add(new CropTransformation(300, 200));

        $this->assertCount(1, $processor->getTransformationNames());
    }

    public function testProcessorCanAddMultipleTransformations(): void
    {
        $file = new File($this->testFile, 'default', $this->storage, $this->diskManager);
        $processor = new ImageProcessor($file, $this->storage, $this->jobStorage);

        $processor->add(new CropTransformation(300, 200))
                  ->add(new BlurTransformation(10))
                  ->add(new QualityTransformation(90));

        $this->assertCount(3, $processor->getTransformationNames());
    }

    public function testProcessorReturnsTransformationNames(): void
    {
        $file = new File($this->testFile, 'default', $this->storage, $this->diskManager);
        $processor = new ImageProcessor($file, $this->storage, $this->jobStorage);

        $processor->add(new CropTransformation(300, 200))
                  ->add(new BlurTransformation(10));

        $names = $processor->getTransformationNames();

        $this->assertContains('crop', $names);
        $this->assertContains('blur', $names);
    }

    public function testProcessorReturnsConfigurations(): void
    {
        $file = new File($this->testFile, 'default', $this->storage, $this->diskManager);
        $processor = new ImageProcessor($file, $this->storage, $this->jobStorage);

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
        $processor = new ImageProcessor($file, $this->storage, $this->jobStorage);

        $processor->add(new CropTransformation(300, 200))
                  ->add(new BlurTransformation(10));

        $this->assertCount(2, $processor->getTransformationNames());

        $processor->clear();

        $this->assertCount(0, $processor->getTransformationNames());
    }

    public function testProcessorIsFluentInterface(): void
    {
        $file = new File($this->testFile, 'default', $this->storage, $this->diskManager);
        $processor = new ImageProcessor($file, $this->storage, $this->jobStorage);

        $result = $processor->add(new CropTransformation(300, 200));

        $this->assertInstanceOf(ImageProcessor::class, $result);
    }
}
