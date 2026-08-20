<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Image\Darkroom;

use claviska\SimpleImage;
use Modufolio\Appkit\Image\Darkroom\GdLib;
use Modufolio\Appkit\Toolkit\Dir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

class SimpleImageMock extends SimpleImage
{
    public int $sharpen = 50;

    public function sharpen(int $amount = 50): static
    {
        $this->sharpen = $amount;

        return $this;
    }
}

#[RequiresPhpExtension('gd')]
#[CoversClass(GdLib::class)]
class GdLibTest extends TestCase
{
    public const FIXTURES = __DIR__.'/../fixtures/image';
    public const TMP = __DIR__.'/Image.Darkroom.GdLib';

    public function setUp(): void
    {
        Dir::make(static::TMP);
    }

    public function tearDown(): void
    {
        Dir::remove(static::TMP);
    }

    public function testProcess(): void
    {
        $gd = new GdLib();

        copy(static::FIXTURES.'/cat.jpg', $file = static::TMP.'/cat.jpg');

        $this->assertSame([
            'autoOrient' => true,
            'blur' => false,
            'crop' => false,
            'format' => null,
            'grayscale' => false,
            'height' => 533,
            'quality' => 90,
            'scaleHeight' => 1.0,
            'scaleWidth' => 1.0,
            'sharpen' => null,
            'width' => 800,
            'sourceWidth' => 800,
            'sourceHeight' => 533,
        ], $gd->process($file));
    }

    public function testProcessWithResize(): void
    {
        $gd = new GdLib();
        copy(static::FIXTURES.'/cat.jpg', $file = static::TMP.'/cat.jpg');

        $result = $gd->process($file, ['width' => 100]);

        $this->assertSame(100, $result['width']);
        $this->assertSame([100, 67], array_slice(getimagesize($file) ?: [], 0, 2));
    }

    public function testProcessWithCenterCrop(): void
    {
        $gd = new GdLib();
        copy(static::FIXTURES.'/cat.jpg', $file = static::TMP.'/cat.jpg');

        $result = $gd->process($file, ['width' => 100, 'height' => 80, 'crop' => true]);

        $this->assertSame('center', $result['crop']);
        $this->assertSame([100, 80], array_slice(getimagesize($file) ?: [], 0, 2));
    }

    public function testProcessWithCropAnchor(): void
    {
        $gd = new GdLib();
        copy(static::FIXTURES.'/cat.jpg', $file = static::TMP.'/cat.jpg');

        $result = $gd->process($file, ['width' => 100, 'height' => 100, 'crop' => 'top left']);

        $this->assertSame('top left', $result['crop']);
        $this->assertSame([100, 100], array_slice(getimagesize($file) ?: [], 0, 2));
    }

    public function testProcessWithFocalPointCrop(): void
    {
        $gd = new GdLib();
        copy(static::FIXTURES.'/cat.jpg', $file = static::TMP.'/cat.jpg');

        $result = $gd->process($file, ['width' => 100, 'height' => 100, 'crop' => '30%,60%']);

        $this->assertSame('30%,60%', $result['crop']);
        $this->assertSame([100, 100], array_slice(getimagesize($file) ?: [], 0, 2));
    }

    public function testProcessWithBlur(): void
    {
        $gd = new GdLib();
        copy(static::FIXTURES.'/cat.jpg', $file = static::TMP.'/cat.jpg');

        $this->assertSame(10, $gd->process($file, ['blur' => true])['blur']);

        $this->assertSame(3, $gd->process($file, ['blur' => 3])['blur']);
    }

    public function testProcessWithGrayscale(): void
    {
        $gd = new GdLib();
        copy(static::FIXTURES.'/cat.jpg', $file = static::TMP.'/cat.jpg');

        $result = $gd->process($file, ['grayscale' => true, 'width' => 50]);

        $this->assertTrue($result['grayscale']);

        // verify pixels are actually desaturated
        $image = imagecreatefromjpeg($file);
        $this->assertNotFalse($image);
        $rgb = imagecolorat($image, 25, 20);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        imagedestroy($image);

        $this->assertLessThanOrEqual(2, max($r, $g, $b) - min($r, $g, $b));
    }

    public function testProcessWithSharpen(): void
    {
        $gd = new GdLib();
        copy(static::FIXTURES.'/cat.jpg', $file = static::TMP.'/cat.jpg');

        $this->assertSame(50, $gd->process($file, ['sharpen' => true])['sharpen']);
        $this->assertSame(25, $gd->process($file, ['sharpen' => 25])['sharpen']);
    }

    public function testProcessWithoutAutoOrient(): void
    {
        $gd = new GdLib(['autoOrient' => false]);
        copy(static::FIXTURES.'/cat.jpg', $file = static::TMP.'/cat.jpg');

        $this->assertFalse($gd->process($file)['autoOrient']);
    }

    public function testProcessWithFormat(): void
    {
        $gd = new GdLib(['format' => 'webp']);
        copy(static::FIXTURES.'/cat.jpg', $file = static::TMP.'/cat.jpg');
        $this->assertSame('webp', $gd->process($file)['format']);
    }

    /**
     * @throws \ReflectionException
     */
    public function testSharpen(): void
    {
        $gd = new GdLib();

        $method = new \ReflectionMethod(get_class($gd), 'sharpen');
        $method->setAccessible(true);

        $simpleImage = new SimpleImageMock();

        $result = $method->invoke($gd, $simpleImage, [
            'sharpen' => 50,
        ]);

        $this->assertSame(50, $result->sharpen);
    }

    public function testSharpenWithoutValue(): void
    {
        $gd = new GdLib();

        $method = new \ReflectionMethod(get_class($gd), 'sharpen');
        $method->setAccessible(true);

        $simpleImage = new SimpleImageMock();

        $result = $method->invoke($gd, $simpleImage, [
            'sharpen' => null,
        ]);

        $this->assertSame(50, $result->sharpen);
    }
}
