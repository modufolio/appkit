<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Tests\Unit\Image;

use PHPUnit\Framework\Attributes\CoversClass;

use Modufolio\Appkit\Image\Dimensions;
use Modufolio\Appkit\Image\Exif;
use Modufolio\Appkit\Image\Image;
use PHPUnit\Framework\TestCase;

#[CoversClass(Image::class)]
class ImageTest extends TestCase
{
    public const FIXTURES = __DIR__ . '/fixtures';

    protected function _image($file = 'cat.jpg'): Image
    {
        return new Image(static::FIXTURES . '/image/' . $file);
    }

    public function testDimensions(): void
    {
        // jpg
        $file = $this->_image();
        $this->assertInstanceOf(Dimensions::class, $file->dimensions());

        // svg with width and height
        $file = $this->_image('square.svg');
        $this->assertSame(100, $file->dimensions()->width());
        $this->assertSame(100, $file->dimensions()->height());

        // svg with viewBox
        $file = $this->_image('circle.svg');
        $this->assertSame(50, $file->dimensions()->width());
        $this->assertSame(50, $file->dimensions()->height());

        // webp
        $file = $this->_image('valley.webp');
        $this->assertSame(550, $file->dimensions()->width());
        $this->assertSame(368, $file->dimensions()->height());

        // non-image file
        $file = $this->_image('blank.pdf');
        $this->assertSame(0, $file->dimensions()->width());
        $this->assertSame(0, $file->dimensions()->height());

        // cached object
        $this->assertInstanceOf(Dimensions::class, $file->dimensions());
    }

    public function testExif(): void
    {
        $file = $this->_image();
        $this->assertInstanceOf(Exif::class, $file->exif());
        // cached object
        $this->assertInstanceOf(Exif::class, $file->exif());
    }

    public function testHeight(): void
    {
        $file = $this->_image();
        $this->assertSame(533, $file->height());
    }




    public function testImagesize(): void
    {
        $file = $this->_image();
        $this->assertIsArray($file->imagesize());
        $this->assertSame(800, $file->imagesize()[0]);
    }

    public function testIsPortrait(): void
    {
        $file = $this->_image();
        $this->assertFalse($file->isPortrait());
    }

    public function testIsLandscape(): void
    {
        $file = $this->_image();
        $this->assertTrue($file->isLandscape());
    }

    public function testIsSquare(): void
    {
        $file = $this->_image();
        $this->assertFalse($file->isSquare());
    }

    public function testIsResizable(): void
    {
        $file = $this->_image();
        $this->assertTrue($file->isResizable());

        // Skip HEIC test if fixture doesn't exist
        $heicPath = static::FIXTURES . '/image/test.heic';
        if (file_exists($heicPath)) {
            $file = $this->_image('test.heic');
            $this->assertFalse($file->isResizable());
        } else {
            $this->markTestSkipped('HEIC fixture file not available');
        }
    }

    public function testIsViewable(): void
    {
        $file = $this->_image();
        $this->assertTrue($file->isResizable());

        // Skip HEIC test if fixture doesn't exist
        $heicPath = static::FIXTURES . '/image/test.heic';
        if (file_exists($heicPath)) {
            $file = $this->_image('test.heic');
            $this->assertFalse($file->isResizable());
        } else {
            $this->markTestSkipped('HEIC fixture file not available');
        }
    }


    public function testOrientation(): void
    {
        $file = $this->_image();
        $this->assertSame('landscape', $file->orientation());
    }

    public function testRatio(): void
    {
        $image  = $this->_image();
        $this->assertEqualsWithDelta(1.5009380863039, $image->ratio(), 0.0001);
    }

    public function testToArray(): void
    {
        $file = $this->_image();

        $this->assertIsArray($file->toArray()['exif']);
        $this->assertIsArray($file->toArray()['dimensions']);
    }

    public function testWidth(): void
    {
        $file = $this->_image();
        $this->assertSame(800, $file->width());
    }
}
