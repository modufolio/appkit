<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Toolkit;

use Modufolio\Appkit\Toolkit\Dir;
use Modufolio\Appkit\Toolkit\F;
use Modufolio\Appkit\Toolkit\Mime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Mime::class)]
class MimeTest extends TestCase
{
    public const FIXTURES = __DIR__.'/fixtures/mime';

    protected string $fixtures = __DIR__.'/fixtures/mime';
    protected string $tmp = __DIR__.'/tmp-mime';

    public function setUp(): void
    {
        Dir::make($this->tmp);
    }

    public function tearDown(): void
    {
        Dir::remove($this->tmp);
    }

    public function testFix(): void
    {
        // no fix applied
        $this->assertSame('text/plain', Mime::fix('test.txt', 'text/plain', 'txt'));
        $this->assertNull(Mime::fix('test.txt'));

        // fixed mime types
        $this->assertSame('text/css', Mime::fix('test.css', 'text/plain', 'css'));
        $this->assertSame('text/css', Mime::fix('test.css', 'text/x-asm', 'css'));
        $this->assertSame('application/json', Mime::fix('test.json', 'text/plain', 'json'));
        $this->assertSame('image/svg+xml', Mime::fix('test.svg', 'image/svg', 'svg'));
    }

    public function testFromExtension(): void
    {
        $this->assertSame('image/png', Mime::fromExtension('png'));

        // first entry of multiple mime types
        $this->assertSame('image/jpeg', Mime::fromExtension('jpg'));

        // unknown extension
        $this->assertNull(Mime::fromExtension('foo'));
    }

    public function testFromFileInfo(): void
    {
        F::write($file = $this->tmp.'/test.txt', 'test');

        $this->assertSame('text/plain', Mime::fromFileInfo($file));
        $this->assertFalse(Mime::fromFileInfo($this->tmp.'/does-not-exist.txt'));
    }

    public function testFromMimeContentType(): void
    {
        F::write($file = $this->tmp.'/test.txt', 'test');

        $this->assertSame('text/plain', Mime::fromMimeContentType($file));
        $this->assertFalse(Mime::fromMimeContentType($this->tmp.'/does-not-exist.txt'));
    }

    public function testFromSvg(): void
    {
        $this->assertSame('image/svg+xml', Mime::fromSvg($this->fixtures.'/optimized.svg'));
        $this->assertSame('image/svg+xml', Mime::fromSvg($this->fixtures.'/unoptimized.svg'));
    }

    public function testFromSvgMissingFile(): void
    {
        $this->assertFalse(Mime::fromSvg($this->tmp.'/does-not-exist.svg'));
    }

    public function testFromSvgEmptyFile(): void
    {
        F::write($file = $this->tmp.'/empty.svg', '');

        $this->assertFalse(Mime::fromSvg($file));
    }

    public function testFromSvgMalformedFile(): void
    {
        F::write($file = $this->tmp.'/malformed.svg', '<svg><broken');

        $this->assertFalse(Mime::fromSvg($file));
    }

    public function testFromSvgWrongRootElement(): void
    {
        F::write($file = $this->tmp.'/wrong.svg', '<html><body></body></html>');

        $this->assertFalse(Mime::fromSvg($file));
    }

    public function testIsAccepted(): void
    {
        $this->assertTrue(Mime::isAccepted('image/jpeg', 'image/jpeg'));
        $this->assertTrue(Mime::isAccepted('image/jpeg', 'text/html, image/*;q=0.8'));
        $this->assertFalse(Mime::isAccepted('image/jpeg', 'text/html, application/json'));
    }

    public function testMatches(): void
    {
        $this->assertTrue(Mime::matches('image/jpeg', 'image/jpeg'));
        $this->assertTrue(Mime::matches('image/jpeg', 'image/*'));
        $this->assertTrue(Mime::matches('image/jpeg', '*/*'));
        $this->assertFalse(Mime::matches('image/jpeg', 'text/*'));
    }

    public function testToExtension(): void
    {
        $this->assertSame('png', Mime::toExtension('image/png'));

        // mime type in an array entry
        $this->assertSame('jpg', Mime::toExtension('image/jpeg'));

        // unknown mime type
        $this->assertFalse(Mime::toExtension('unknown/mime'));
        $this->assertFalse(Mime::toExtension(null));
    }

    public function testToExtensions(): void
    {
        $this->assertSame(['jpg', 'jpeg', 'jpe'], Mime::toExtensions('image/jpeg'));
        $this->assertSame(['png'], Mime::toExtensions('image/png'));
        $this->assertSame([], Mime::toExtensions('unknown/mime'));
    }

    public function testToExtensionsWildcards(): void
    {
        $extensions = Mime::toExtensions('image/*', true);

        $this->assertContains('png', $extensions);
        $this->assertContains('jpg', $extensions);
        $this->assertContains('svg', $extensions);
        $this->assertNotContains('txt', $extensions);
    }

    public function testType(): void
    {
        F::write($file = $this->tmp.'/test.txt', 'test');

        $this->assertSame('text/plain', Mime::type($file));
    }

    public function testTypeWithFixedMime(): void
    {
        F::write($file = $this->tmp.'/test.json', '{"a": 1}');

        $this->assertSame('application/json', Mime::type($file));
    }

    public function testTypeWithExtensionFallback(): void
    {
        // the file does not exist, so the mime type
        // can only be guessed by extension
        $this->assertSame('application/pdf', Mime::type($this->tmp.'/does-not-exist.pdf'));
    }

    public function testTypeWithCustomExtension(): void
    {
        $this->assertSame('image/png', Mime::type($this->tmp.'/does-not-exist', 'png'));
    }

    public function testTypes(): void
    {
        $this->assertSame(Mime::$types, Mime::types());
        $this->assertSame('image/png', Mime::types()['png']);
    }
}
