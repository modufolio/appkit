<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Image\Transformations;

use Modufolio\Appkit\Image\File;
use Modufolio\Appkit\Image\Storage;
use Modufolio\Appkit\Image\Transformations\SharpenTransformation;
use Modufolio\Appkit\Toolkit\Dir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SharpenTransformation::class)]
class SharpenTransformationTest extends TestCase
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
        $transformation = new SharpenTransformation(75);

        $this->assertSame('sharpen', $transformation->name());
        $this->assertSame(['amount' => 75], $transformation->config());
    }

    public function testAmountIsClampedToMinimumOfZero(): void
    {
        $this->assertSame(['amount' => 0], (new SharpenTransformation(-10))->config());
        $this->assertSame(['amount' => 50], (new SharpenTransformation())->config());
    }

    public function testApplyToResizableImage(): void
    {
        $file = new File($this->tmp.'/photo.png', 'default', $this->storage);

        $result = (new SharpenTransformation(50))->apply($file, $this->storage);

        // sharpen is not part of the filename attributes
        $this->assertSame('photo.png', basename($result['root']));
        $this->assertStringStartsWith('/media/images/default/', $result['url']);
    }

    public function testApplyToNonResizableFile(): void
    {
        $file = new File($this->tmp.'/notes.txt', 'default', $this->storage);

        $result = (new SharpenTransformation())->apply($file, $this->storage);

        $this->assertSame($file->root(), $result['root']);
        $this->assertSame($file->mediaUrl(), $result['url']);
    }
}
