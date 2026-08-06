<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Image\Transformations;

use Modufolio\Appkit\Image\File;
use Modufolio\Appkit\Image\Storage;
use Modufolio\Appkit\Image\Transformation;
use Modufolio\Appkit\Image\Transformations\ResizeTransformation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResizeTransformation::class)]
class ResizeTransformationTest extends TestCase
{
    private string $testFile;

    protected function setUp(): void
    {
        $this->testFile = sys_get_temp_dir().'/test_image_'.uniqid().'.txt';
        file_put_contents($this->testFile, 'test');
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

    public function testApplyToResizableImage(): void
    {
        $imagePath = sys_get_temp_dir().'/appkit-'.uniqid().'.png';
        $image = imagecreatetruecolor(10, 10);
        imagepng($image, $imagePath);
        imagedestroy($image);

        try {
            $storage = new Storage();
            $file = new File($imagePath, 'default', $storage);

            $result = (new ResizeTransformation(300, 200, 80))->apply($file, $storage);

            $this->assertStringEndsWith('-300x200-q80.png', basename($result['root']));
            $this->assertStringEndsWith('-300x200-q80.png', basename($result['url']));
        } finally {
            unlink($imagePath);
        }
    }

    public function testApplyToNonResizableFile(): void
    {
        $storage = new Storage();
        $file = new File($this->testFile, 'default', $storage);

        $result = (new ResizeTransformation(300))->apply($file, $storage);

        $this->assertSame($file->root(), $result['root']);
        $this->assertSame($file->mediaUrl(), $result['url']);
    }
}
