<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Tests\Unit\Toolkit;

use Modufolio\Appkit\Toolkit\Escape;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Escape::class)]
class EscapeTest extends TestCase
{
    public function testHtmlEscapesElementText(): void
    {
        $this->assertSame('&lt;b&gt;', Escape::html('<b>'));
        $this->assertSame('Tom &amp; Jerry', Escape::html('Tom & Jerry'));
    }

    public function testAttrEscapesQuotesAndSpaces(): void
    {
        // The attribute escaper encodes the quote that would otherwise break out
        // of the attribute, plus spaces (unquoted-attribute defence).
        $this->assertSame('a&quot;b', Escape::attr('a"b'));
    }

    public function testJsEscapesStringDelimiters(): void
    {
        // A double quote inside a JS string literal becomes a \xNN sequence.
        $this->assertSame('a\\x22b', Escape::js('a"b'));
    }

    public function testCssEscapesNonAlphanumerics(): void
    {
        $this->assertSame('a\\20 b', Escape::css('a b'));
    }

    public function testUrlPercentEncodes(): void
    {
        $this->assertSame('a%20b%26c', Escape::url('a b&c'));
    }
}
