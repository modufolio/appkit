<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Image\Transformations;

use Modufolio\Appkit\Image\File;
use Modufolio\Appkit\Image\Storage;
use Modufolio\Appkit\Image\Transformations\CropTransformation;
use Modufolio\Appkit\Toolkit\Dir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CropTransformation::class)]
class CropTransformationTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/appkit-'.uniqid();
        mkdir($this->tmp, 0o777, true);
    }

    protected function tearDown(): void
    {
        Dir::remove($this->tmp);
    }

    public function testCropTransformationName(): void
    {
        $transformation = new CropTransformation(300, 200);
        $this->assertSame('crop', $transformation->name());
    }

    public function testCropTransformationConfig(): void
    {
        $transformation = new CropTransformation(300, 200, 'center');
        $config = $transformation->config();

        $this->assertSame(300, $config['width']);
        $this->assertSame(200, $config['height']);
        $this->assertSame('center', $config['crop']);
    }

    public function testCropTransformationDefaultMode(): void
    {
        $transformation = new CropTransformation(300, 200);
        $config = $transformation->config();

        $this->assertSame('center', $config['crop']);
    }

    public function testCropTransformationSquare(): void
    {
        $transformation = new CropTransformation(300);
        $config = $transformation->config();

        $this->assertSame(300, $config['width']);
        $this->assertSame(300, $config['height']);
    }

    public function testCropTransformationCustomMode(): void
    {
        $transformation = new CropTransformation(300, 200, 'top-left');
        $config = $transformation->config();

        $this->assertSame('top-left', $config['crop']);
    }

    public function testApplyToResizableImage(): void
    {
        $image = imagecreatetruecolor(10, 10);
        imagepng($image, $this->tmp.'/photo.png');
        imagedestroy($image);

        $storage = new Storage();
        $file = new File($this->tmp.'/photo.png', 'default', $storage);

        $result = (new CropTransformation(100, 80))->apply($file, $storage);

        $this->assertSame('photo-100x80-crop.png', basename($result['root']));
        $this->assertSame('photo-100x80-crop.png', basename($result['url']));
    }

    public function testApplyToNonResizableFile(): void
    {
        file_put_contents($this->tmp.'/notes.txt', 'text');

        $storage = new Storage();
        $file = new File($this->tmp.'/notes.txt', 'default', $storage);

        $result = (new CropTransformation(100))->apply($file, $storage);

        $this->assertSame($file->root(), $result['root']);
        $this->assertSame($file->mediaUrl(), $result['url']);
    }
}
