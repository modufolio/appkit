<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Image\Transformations;

use Modufolio\Appkit\Image\File;
use Modufolio\Appkit\Image\Storage;
use Modufolio\Appkit\Image\Transformations\GrayscaleTransformation;
use Modufolio\Appkit\Toolkit\Dir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GrayscaleTransformation::class)]
class GrayscaleTransformationTest extends TestCase
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
        $transformation = new GrayscaleTransformation();

        $this->assertSame('grayscale', $transformation->name());
        $this->assertSame([], $transformation->config());
    }

    public function testApplyToResizableImage(): void
    {
        $file = new File($this->tmp.'/photo.png', 'default', $this->storage);

        $result = (new GrayscaleTransformation())->apply($file, $this->storage);

        $this->assertSame('photo-bw.png', basename($result['root']));
        $this->assertSame('photo-bw.png', basename($result['url']));
    }

    public function testApplyToNonResizableFile(): void
    {
        $file = new File($this->tmp.'/notes.txt', 'default', $this->storage);

        $result = (new GrayscaleTransformation())->apply($file, $this->storage);

        $this->assertSame($file->root(), $result['root']);
        $this->assertSame($file->mediaUrl(), $result['url']);
    }
}
