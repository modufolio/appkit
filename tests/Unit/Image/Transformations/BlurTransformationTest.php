<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Image\Transformations;

use Modufolio\Appkit\Image\File;
use Modufolio\Appkit\Image\Storage;
use Modufolio\Appkit\Image\Transformations\BlurTransformation;
use Modufolio\Appkit\Toolkit\Dir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlurTransformation::class)]
class BlurTransformationTest extends TestCase
{
    private string $tmp;
    private Storage $storage;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/appkit-'.uniqid();
        mkdir($this->tmp, 0o777, true);

        $image = imagecreatetruecolor(10, 10);
        imagepng($image, $this->tmp.'/photo.png');
        imagedestroy($image);

        file_put_contents($this->tmp.'/notes.txt', 'text');

        $this->storage = new Storage();
    }

    protected function tearDown(): void
    {
        Dir::remove($this->tmp);
    }

    public function testNameAndConfig(): void
    {
        $transformation = new BlurTransformation(15);

        $this->assertSame('blur', $transformation->name());
        $this->assertSame(['intensity' => 15], $transformation->config());
    }

    public function testIntensityIsClampedToMinimumOfOne(): void
    {
        $this->assertSame(['intensity' => 1], (new BlurTransformation(0))->config());
        $this->assertSame(['intensity' => 1], (new BlurTransformation(-5))->config());
        $this->assertSame(['intensity' => 10], (new BlurTransformation())->config());
    }

    public function testApplyToResizableImage(): void
    {
        $file = new File($this->tmp.'/photo.png', 'default', $this->storage);

        $result = (new BlurTransformation(10))->apply($file, $this->storage);

        $this->assertSame('photo-blur10.png', basename($result['root']));
        $this->assertSame('photo-blur10.png', basename($result['url']));
        $this->assertStringStartsWith('/media/images/default/', $result['url']);
    }

    public function testApplyToNonResizableFile(): void
    {
        $file = new File($this->tmp.'/notes.txt', 'default', $this->storage);

        $result = (new BlurTransformation())->apply($file, $this->storage);

        $this->assertSame($file->root(), $result['root']);
        $this->assertSame($file->mediaUrl(), $result['url']);
    }
}
