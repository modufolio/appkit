<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Image\Transformations;

use Modufolio\Appkit\Image\File;
use Modufolio\Appkit\Image\Storage;
use Modufolio\Appkit\Image\Transformations\QualityTransformation;
use Modufolio\Appkit\Toolkit\Dir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QualityTransformation::class)]
class QualityTransformationTest extends TestCase
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
        $transformation = new QualityTransformation(80);

        $this->assertSame('quality', $transformation->name());
        $this->assertSame(['quality' => 80], $transformation->config());
    }

    public function testQualityIsClampedBetweenOneAndHundred(): void
    {
        $this->assertSame(['quality' => 1], (new QualityTransformation(0))->config());
        $this->assertSame(['quality' => 100], (new QualityTransformation(150))->config());
        $this->assertSame(['quality' => 90], (new QualityTransformation())->config());
    }

    public function testApplyToResizableImage(): void
    {
        $file = new File($this->tmp.'/photo.png', 'default', $this->storage);

        $result = (new QualityTransformation(75))->apply($file, $this->storage);

        $this->assertSame('photo-q75.png', basename($result['root']));
        $this->assertSame('photo-q75.png', basename($result['url']));
    }

    public function testApplyToNonResizableFile(): void
    {
        $file = new File($this->tmp.'/notes.txt', 'default', $this->storage);

        $result = (new QualityTransformation())->apply($file, $this->storage);

        $this->assertSame($file->root(), $result['root']);
        $this->assertSame($file->mediaUrl(), $result['url']);
    }
}
