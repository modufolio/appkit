<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Image;

use Modufolio\Appkit\Image\ImageException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImageException::class)]
class ImageExceptionTest extends TestCase
{
    public function testFileNotFound(): void
    {
        $e = ImageException::fileNotFound('/tmp/missing.jpg');

        $this->assertInstanceOf(ImageException::class, $e);
        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertSame('Image file not found: /tmp/missing.jpg', $e->getMessage());
    }

    public function testFileNotReadable(): void
    {
        $e = ImageException::fileNotReadable('/tmp/locked.jpg');

        $this->assertSame('Image file is not readable: /tmp/locked.jpg', $e->getMessage());
    }

    public function testInvalidImageType(): void
    {
        $e = ImageException::invalidImageType('/tmp/file.bmp', 'bmp');

        $this->assertSame("Invalid or unsupported image type 'bmp' for file: /tmp/file.bmp", $e->getMessage());
    }

    public function testMimeTypeMismatch(): void
    {
        $e = ImageException::mimeTypeMismatch('/tmp/fake.jpg', 'jpg', 'image/png');

        $this->assertStringContainsString('MIME type mismatch for file: /tmp/fake.jpg', $e->getMessage());
        $this->assertStringContainsString("Extension 'jpg' does not match MIME type 'image/png'", $e->getMessage());
    }

    public function testTransformationFailed(): void
    {
        $previous = new \RuntimeException('gd error');
        $e = ImageException::transformationFailed('resize failed', $previous);

        $this->assertSame('Image transformation failed: resize failed', $e->getMessage());
        $this->assertSame($previous, $e->getPrevious());

        $withoutPrevious = ImageException::transformationFailed('oops');
        $this->assertNull($withoutPrevious->getPrevious());
    }

    public function testPathTraversalAttempt(): void
    {
        $e = ImageException::pathTraversalAttempt('../../etc/passwd');

        $this->assertSame('Path traversal detected in: ../../etc/passwd', $e->getMessage());
    }
}
