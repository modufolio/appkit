<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Tests\Unit\Image\Darkroom;

use Modufolio\Appkit\Image\Darkroom\GdLib;
use Modufolio\Appkit\Toolkit\Dir;
use claviska\SimpleImage;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

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
class GdLibTest extends TestCase
{
    public const FIXTURES = __DIR__ . '/../fixtures/image';
    public const TMP      = __DIR__ . '/Image.Darkroom.GdLib';

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

        copy(static::FIXTURES . '/cat.jpg', $file = static::TMP . '/cat.jpg');

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


    public function testProcessWithFormat(): void
    {
        $gd = new GdLib(['format' => 'webp']);
        copy(static::FIXTURES . '/cat.jpg', $file = static::TMP . '/cat.jpg');
        $this->assertSame('webp', $gd->process($file)['format']);
    }

    /**
     * @throws \ReflectionException
     */
    public function testSharpen(): void
    {
        $gd = new GdLib();

        $method = new ReflectionMethod(get_class($gd), 'sharpen');
        $method->setAccessible(true);

        $simpleImage = new SimpleImageMock();

        $result = $method->invoke($gd, $simpleImage, [
            'sharpen' => 50
        ]);

        $this->assertSame(50, $result->sharpen);
    }

    public function testSharpenWithoutValue(): void
    {
        $gd = new GdLib();

        $method = new ReflectionMethod(get_class($gd), 'sharpen');
        $method->setAccessible(true);

        $simpleImage = new SimpleImageMock();

        $result = $method->invoke($gd, $simpleImage, [
            'sharpen' => null
        ]);

        $this->assertSame(50, $result->sharpen);
    }
}
