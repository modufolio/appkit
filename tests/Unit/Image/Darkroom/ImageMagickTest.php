<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Image\Darkroom;

use Modufolio\Appkit\Image\Darkroom\ImageMagick;
use Modufolio\Appkit\Tests\Attribute\RequiresCommand;
use Modufolio\Appkit\Tests\Traits\RequiresCommandTrait;
use Modufolio\Appkit\Toolkit\Dir;
use Modufolio\Appkit\Toolkit\F;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[RequiresCommand('convert')]
#[CoversClass(ImageMagick::class)]
class ImageMagickTest extends TestCase
{
    use RequiresCommandTrait;
    public const FIXTURES = __DIR__.'/../fixtures/image';
    public const TMP = __DIR__.'/Image.Darkroom.ImageMagick';

    /**
     * @throws \Exception
     */
    /**
     * The ImageMagick binary these tests drive.
     *
     * ImageMagick 7 ships `magick` (the framework default), 6 ships only
     * `convert` — which is what Debian and Ubuntu package, so CI runs against
     * it. Resolve whichever is present instead of hard-coding one, so the same
     * assertions cover both.
     */
    private string $bin;

    public function setUp(): void
    {
        $bin = null;

        foreach (['magick', 'convert'] as $candidate) {
            if ('' !== trim((string) shell_exec('command -v '.escapeshellarg($candidate)))) {
                $bin = $candidate;
                break;
            }
        }

        if (null === $bin) {
            $this->markTestSkipped('ImageMagick is not installed');
        }

        $this->bin = $bin;

        Dir::make(static::TMP);
    }

    public function tearDown(): void
    {
        Dir::remove(static::TMP);
    }

    public function testProcess(): void
    {
        $im = new ImageMagick(['bin' => $this->bin]);

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
            'bin' => $this->bin,
            'interlace' => false,
            'threads' => 1,
            'sourceWidth' => 800,
            'sourceHeight' => 533,
        ], $im->process($file));
    }

    public function testSharpen(): void
    {
        $im = new ImageMagick(['bin' => $this->bin]);

        $method = new \ReflectionMethod(get_class($im), 'sharpen');
        $method->setAccessible(true);

        $result = $method->invoke($im, '', [
            'sharpen' => 50,
        ]);

        $this->assertSame("-sharpen '0x0.5'", $result);
    }

    public function testSharpenWithoutValue(): void
    {
        $im = new ImageMagick(['bin' => $this->bin]);

        $method = new \ReflectionMethod(get_class($im), 'sharpen');
        $method->setAccessible(true);

        $result = $method->invoke($im, '', [
            'sharpen' => null,
        ]);

        $this->assertNull($result);
    }

    public function testSaveWithFormat(): void
    {
        $im = new ImageMagick(['bin' => $this->bin, 'format' => 'webp']);

        copy(static::FIXTURES.'/cat.jpg', $file = static::TMP.'/cat.jpg');
        $this->assertFalse(F::exists($webp = static::TMP.'/cat.webp'));
        $im->process($file);
        $this->assertTrue(F::exists($webp));
    }

    #[DataProvider('keepColorProfileStripMetaProvider')]
    public function testKeepColorProfileStripMeta(string $basename, bool $crop): void
    {
        $im = new ImageMagick([
            'bin' => $this->bin,
            'crop' => $crop,
            'width' => 250, // do some arbitrary transformation
        ]);

        copy(static::FIXTURES.'/'.$basename, $file = static::TMP.'/'.$basename);

        // test if profile has been kept
        // errors have to be redirected to /dev/null, otherwise they would be printed to stdout by ImageMagick
        $originalProfile = shell_exec('identify -format "%[profile:icc]" '.escapeshellarg($file).' 2>/dev/null');
        $im->process($file);
        $profile = shell_exec('identify -format "%[profile:icc]" '.escapeshellarg($file).' 2>/dev/null');

        if ('png' === F::extension($basename)) {
            // ensure that the profile has been stripped from PNG files, because
            // ImageMagick cannot keep it while stripping all other metadata
            // (tested with ImageMagick 7.0.11-14 Q16 x86_64 2021-05-31)
            $this->assertNull($profile);
        } else {
            // ensure that the profile has been kept for all other file types
            $this->assertSame($originalProfile, $profile);
        }

        // ensure that other metadata has been stripped
        $meta = (string) shell_exec('identify -verbose '.escapeshellarg($file));
        $this->assertStringNotContainsString('photoshop:CaptionWriter', $meta);
        $this->assertStringNotContainsString('GPS', $meta);
    }

    /**
     * @return list<array<int, mixed>>
     */
    public static function keepColorProfileStripMetaProvider(): array
    {
        return [
            ['cat.jpg', false],
            ['cat.jpg', true],
            ['onigiri-adobe-rgb-gps.jpg', false],
            ['onigiri-adobe-rgb-gps.jpg', true],
            ['onigiri-adobe-rgb-gps.webp', false],
            ['onigiri-adobe-rgb-gps.webp', true],
            ['png-adobe-rgb-gps.png', false],
            ['png-adobe-rgb-gps.png', true],
            ['png-srgb-gps.png', false],
            ['png-srgb-gps.png', true],
        ];
    }
}
