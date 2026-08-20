<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Image;

use Modufolio\Appkit\Image\File;
use Modufolio\Appkit\Image\ImageVariant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImageVariant::class)]
class ImageVariantTest extends TestCase
{
    private string $testFile;
    private File $original;

    protected function setUp(): void
    {
        $this->testFile = sys_get_temp_dir().'/appkit-'.uniqid().'.png';
        $image = imagecreatetruecolor(10, 10);
        imagepng($image, $this->testFile);
        imagedestroy($image);

        $this->original = new File($this->testFile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function variant(array $overrides = []): ImageVariant
    {
        return new ImageVariant($overrides + [
            'root' => '/media/images/default/abc/photo-300x200.png',
            'url' => '/media/images/default/abc/photo-300x200.png',
            'original' => $this->original,
            'modifications' => ['width' => 300, 'height' => 200],
        ]);
    }

    public function testGetters(): void
    {
        $variant = $this->variant();

        $this->assertSame('/media/images/default/abc/photo-300x200.png', $variant->root());
        $this->assertSame('/media/images/default/abc/photo-300x200.png', $variant->url());
        $this->assertSame('photo-300x200.png', $variant->filename());
        $this->assertSame('png', $variant->extension());
        $this->assertSame('photo-300x200', $variant->name());
        $this->assertSame(['width' => 300, 'height' => 200], $variant->modifications());
        $this->assertSame($this->original, $variant->original());
    }

    public function testModificationsDefaultToEmptyArray(): void
    {
        $variant = new ImageVariant([
            'root' => '/tmp/a.png',
            'url' => '/tmp/a.png',
            'original' => $this->original,
        ]);

        $this->assertSame([], $variant->modifications());
    }

    public function testExists(): void
    {
        $this->assertFalse($this->variant()->exists());
        $this->assertTrue($this->variant(['root' => $this->testFile])->exists());
    }

    public function testToArray(): void
    {
        $this->assertSame([
            'filename' => 'photo-300x200.png',
            'url' => '/media/images/default/abc/photo-300x200.png',
            'root' => '/media/images/default/abc/photo-300x200.png',
            'modifications' => ['width' => 300, 'height' => 200],
        ], $this->variant()->toArray());
    }

    public function testMissingRootThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing root path');

        new ImageVariant(['url' => '/a', 'original' => $this->original]);
    }

    public function testMissingUrlThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing URL');

        new ImageVariant(['root' => '/a', 'original' => $this->original]);
    }

    public function testMissingOriginalThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing original file');

        new ImageVariant(['root' => '/a', 'url' => '/a']);
    }
}
