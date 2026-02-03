<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Image\Transformations;

use Modufolio\Appkit\Image\Disk;
use Modufolio\Appkit\Image\File;
use Modufolio\Appkit\Image\Storage;
use Modufolio\Appkit\Image\Transformation;
use Modufolio\Appkit\Image\Transformations\ResizeTransformation;
use PHPUnit\Framework\TestCase;

class ResizeTransformationTest extends TestCase
{
    private string $testFile;
    private Storage $storage;

    protected function setUp(): void
    {
        $this->testFile = sys_get_temp_dir() . '/test_image_' . uniqid() . '.txt';
        file_put_contents($this->testFile, 'test');

        $this->storage = new Storage(
            baseMediaRoot: '/media',
            baseMediaUrl: '/media'
        );
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }
    }

    public function testTransformationImplementsInterface(): void
    {
        $transformation = new ResizeTransformation(300, 200);
        $this->assertInstanceOf(Transformation::class, $transformation);
    }

    public function testResizeTransformationName(): void
    {
        $transformation = new ResizeTransformation(300, 200);
        $this->assertSame('resize', $transformation->name());
    }

    public function testResizeTransformationConfig(): void
    {
        $transformation = new ResizeTransformation(300, 200, 90);
        $config = $transformation->config();

        $this->assertSame(300, $config['width']);
        $this->assertSame(200, $config['height']);
        $this->assertSame(90, $config['quality']);
    }

    public function testResizeTransformationWithoutQuality(): void
    {
        $transformation = new ResizeTransformation(300, 200);
        $config = $transformation->config();

        $this->assertSame(300, $config['width']);
        $this->assertSame(200, $config['height']);
        $this->assertArrayNotHasKey('quality', $config);
    }

    public function testResizeTransformationWithOnlyWidth(): void
    {
        $transformation = new ResizeTransformation(300);
        $config = $transformation->config();

        $this->assertSame(300, $config['width']);
        $this->assertArrayNotHasKey('height', $config);
    }
}
