<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Image;

use Modufolio\Appkit\Image\CustomFilename;
use Modufolio\Appkit\Image\ImageException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CustomFilename::class)]
class CustomFilenameTest extends TestCase
{
    public const TEMPLATE = '{{ name }}{{ attributes }}.{{ extension }}';

    public function testToStringWithAllAttributes(): void
    {
        $filename = new CustomFilename('Some File.jpg', static::TEMPLATE, [
            'width' => 300,
            'height' => 200,
            'crop' => 'center',
            'blur' => 10,
            'grayscale' => true,
            'quality' => 80,
        ]);

        $this->assertSame('some-file-300x200-crop-blur10-bw-q80.jpg', $filename->toString());
        $this->assertSame('some-file-300x200-crop-blur10-bw-q80.jpg', (string) $filename);
    }

    public function testToStringWithoutAttributes(): void
    {
        $filename = new CustomFilename('image.png', static::TEMPLATE);

        $this->assertSame('image.png', $filename->toString());
        $this->assertSame('', $filename->attributesToString('-'));
    }

    public function testAttributesToArray(): void
    {
        $filename = new CustomFilename('image.png', static::TEMPLATE, [
            'width' => 100,
            'blur' => 5,
            'quality' => 70,
        ]);

        $this->assertSame([
            'dimensions' => '100x',
            'blur' => 5,
            'q' => 70,
        ], $filename->attributesToArray());
    }

    public function testAttributesToStringWithCustomCrop(): void
    {
        $filename = new CustomFilename('image.png', static::TEMPLATE, [
            'width' => 100,
            'height' => 100,
            'crop' => 'Top Left',
        ]);

        $this->assertSame('-100x100-crop-top-left', $filename->attributesToString('-'));
    }

    public function testBlur(): void
    {
        $this->assertFalse((new CustomFilename('a.jpg', static::TEMPLATE))->blur());
        $this->assertSame(5, (new CustomFilename('a.jpg', static::TEMPLATE, ['blur' => '5']))->blur());
        $this->assertSame(1, (new CustomFilename('a.jpg', static::TEMPLATE, ['blur' => true]))->blur());
    }

    public function testCrop(): void
    {
        $this->assertFalse((new CustomFilename('a.jpg', static::TEMPLATE))->crop());
        $this->assertSame('center', (new CustomFilename('a.jpg', static::TEMPLATE, ['crop' => 'center']))->crop());
    }

    public function testDimensions(): void
    {
        $this->assertSame([], (new CustomFilename('a.jpg', static::TEMPLATE))->dimensions());

        $filename = new CustomFilename('a.jpg', static::TEMPLATE, ['width' => 300, 'height' => 200]);
        $this->assertSame(['width' => 300, 'height' => 200], $filename->dimensions());

        $filename = new CustomFilename('a.jpg', static::TEMPLATE, ['height' => 200]);
        $this->assertSame(['width' => null, 'height' => 200], $filename->dimensions());
    }

    public function testExtension(): void
    {
        $this->assertSame('jpg', (new CustomFilename('a.JPEG', static::TEMPLATE))->extension());
        $this->assertSame('png', (new CustomFilename('a.PNG', static::TEMPLATE))->extension());
        // format attribute wins over the file extension
        $this->assertSame('webp', (new CustomFilename('a.jpg', static::TEMPLATE, ['format' => 'WEBP']))->extension());
    }

    public function testGrayscale(): void
    {
        $this->assertFalse((new CustomFilename('a.jpg', static::TEMPLATE))->grayscale());
        $this->assertTrue((new CustomFilename('a.jpg', static::TEMPLATE, ['grayscale' => true]))->grayscale());
        $this->assertTrue((new CustomFilename('a.jpg', static::TEMPLATE, ['greyscale' => 'true']))->grayscale());
        $this->assertTrue((new CustomFilename('a.jpg', static::TEMPLATE, ['bw' => 1]))->grayscale());
        $this->assertFalse((new CustomFilename('a.jpg', static::TEMPLATE, ['bw' => 'no']))->grayscale());
    }

    public function testName(): void
    {
        $this->assertSame('some-file', (new CustomFilename('Some File.jpg', static::TEMPLATE))->name());
    }

    public function testQuality(): void
    {
        $this->assertFalse((new CustomFilename('a.jpg', static::TEMPLATE))->quality());
        $this->assertFalse((new CustomFilename('a.jpg', static::TEMPLATE, ['quality' => true]))->quality());
        $this->assertSame(80, (new CustomFilename('a.jpg', static::TEMPLATE, ['quality' => '80']))->quality());
    }

    public function testPathTraversalInTemplate(): void
    {
        $this->expectException(ImageException::class);
        $this->expectExceptionMessage('Path traversal detected');

        new CustomFilename('a.jpg', '/media/../secret/{{ name }}.{{ extension }}');
    }

    public function testPathTraversalInFilename(): void
    {
        $this->expectException(ImageException::class);

        new CustomFilename('../etc/passwd.jpg', static::TEMPLATE);
    }

    public function testPathTraversalUrlEncoded(): void
    {
        $this->expectException(ImageException::class);

        new CustomFilename('%2e%2e/passwd.jpg', static::TEMPLATE);
    }
}
