<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Tests\Unit\Image;

use Modufolio\Appkit\Image\Darkroom\GdLib;
use PHPUnit\Framework\TestCase;

class DarkroomTest extends TestCase
{
    public const FIXTURES = __DIR__ . '/fixtures';

    public function file(string|null $driver = null): string
    {
        if ($driver !== null) {
            return static::FIXTURES . '/image/cat-' . $driver . '.jpg';
        }

        return static::FIXTURES . '/image/cat.jpg';
    }

    public function testCropWithoutPosition(): void
    {
        $darkroom = new GdLib();
        $options  = $darkroom->preprocess($this->file(), [
            'crop'  => true,
            'width' => 100
        ]);

        $this->assertSame('center', $options['crop']);
    }

    public function testBlurWithoutPosition(): void
    {
        $darkroom = new GdLib();
        $options  = $darkroom->preprocess($this->file(), [
            'blur' => true,
        ]);

        $this->assertSame(10, $options['blur']);
    }

    public function testQualityWithoutValue(): void
    {
        $darkroom = new GdLib();
        $options  = $darkroom->preprocess($this->file(), [
            'quality' => null,
        ]);

        $this->assertSame(90, $options['quality']);
    }

    public function testSharpenWithoutValue(): void
    {
        $darkroom = new GdLib();
        $options  = $darkroom->preprocess($this->file(), [
            'sharpen' => true,
            'width'   => 100
        ]);

        $this->assertSame(50, $options['sharpen']);
    }

    public function testDefaults(): void
    {
        $darkroom = new GdLib();
        // Use PDF file which won't have image dimensions
        $options  = $darkroom->preprocess(static::FIXTURES . '/image/blank.pdf');

        $this->assertTrue($options['autoOrient']);
        $this->assertFalse($options['crop']);
        $this->assertFalse($options['blur']);
        $this->assertFalse($options['grayscale']);
        $this->assertSame(0, $options['height']);
        $this->assertSame(90, $options['quality']);
        $this->assertSame(0, $options['width']);
    }

    public function testGlobalOptions(): void
    {
        $darkroom = new GdLib([
            'quality' => 20
        ]);

        $options = $darkroom->preprocess($this->file());

        $this->assertSame(20, $options['quality']);
    }

    public function testPassedOptions(): void
    {
        $darkroom = new GdLib([
            'quality' => 20
        ]);

        $options = $darkroom->preprocess($this->file(), [
            'quality' => 30
        ]);

        $this->assertSame(30, $options['quality']);
    }

    public function testProcess(): void
    {
        $darkroom = new GdLib([
            'quality' => 20
        ]);

        $options = $darkroom->process($this->file(), [
            'quality' => 30
        ]);

        $this->assertSame(30, $options['quality']);
    }

    public function testGrayscaleFixes(): void
    {
        $darkroom = new GdLib();

        // grayscale
        $options = $darkroom->preprocess($this->file(), [
            'grayscale' => true
        ]);

        $this->assertTrue($options['grayscale']);

        // greyscale
        $options = $darkroom->preprocess($this->file(), [
            'greyscale' => true
        ]);

        $this->assertTrue($options['grayscale']);
        $this->assertFalse(isset($options['greyscale']));

        // bw
        $options = $darkroom->preprocess($this->file(), [
            'bw' => true
        ]);

        $this->assertTrue($options['grayscale']);
        $this->assertFalse(isset($options['bw']));
    }
}
