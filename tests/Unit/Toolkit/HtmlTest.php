<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Tests\Unit\Toolkit;

use Modufolio\Appkit\Toolkit\Html;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Html::class)]
class HtmlTest extends TestCase
{
    public function testCallStatic(): void
    {
        $this->assertSame('<div>test</div>', Html::div('test'));
        $this->assertSame('<div class="test">test</div>', Html::div('test', ['class' => 'test']));
        $this->assertSame('<hr class="test">', Html::hr(['class' => 'test']));
    }

    #[DataProvider('attrProvider')]
    public function testAttr($input, $value, $expected): void
    {
        $this->assertSame($expected, Html::attr($input, $value));
    }

    public static function attrProvider(): array
    {
        return [
            [[],                         null,  null],
            [['B' => 'b', 'A' => 'a'],   null,  'a="a" b="b"'],
            [['B' => 'b', 'A' => 'a'],   true,  'a="a" b="b"'],
            [['B' => 'b', 'A' => 'a'],   false, 'b="b" a="a"'],
            [['a' => 'a', 'b' => true],  null,  'a="a" b'],
            [['a' => 'a', 'b' => ' '], null, 'a="a" b=" "'],
            [['a' => 'a', 'b' => false], null,  'a="a"'],
            [['a' => 'a', 'b' => null],  null,  'a="a"'],
            [['a' => 'a', 'b' => []],    null,  'a="a"'],
            [['a', 'b' => true],         null,  'a b']
        ];
    }

    public function testAttrArrayValue(): void
    {
        $result = Html::attr('a', ['a', 'b']);
        $this->assertSame('a="a b"', $result);

        $result = Html::attr('a', ['a', 1]);
        $this->assertSame('a="a 1"', $result);

        $result = Html::attr('a', ['a', null]);
        $this->assertSame('a="a"', $result);

        $result = Html::attr('a', ['value' => '&', 'escape' => true]);
        $this->assertSame('a="&amp;"', $result);

        $result = Html::attr('a', ['value' => '&', 'escape' => false]);
        $this->assertSame('a="&"', $result);
    }

    public function testBreaks(): void
    {
        $this->assertSame("line 1<br />\nline 2", Html::breaks("line 1\nline 2"));
    }

    public function testEncode(): void
    {
        $html = Html::encode('äöü');
        $expected = '&auml;&ouml;&uuml;';
        $this->assertSame($expected, $html);

        $html = Html::encode('ä<p>ö</p>');
        $expected = '&auml;&lt;p&gt;&ouml;&lt;/p&gt;';
        $this->assertSame($expected, $html);

        $html = Html::encode('ä<span title="Amazing &amp; great">ö</span>', true);
        $expected = '&auml;<span title="Amazing &amp; great">&ouml;</span>';
        $this->assertSame($expected, $html);

        $this->assertSame('', Html::encode(''));
    }

    public function testEntities(): void
    {
        Html::$entities = null;
        $this->assertTrue(count(Html::entities()) > 0);

        Html::$entities = [];
        $this->assertSame([], Html::entities());

        Html::$entities = ['t' => 'test'];
        $this->assertSame(['t' => 'test'], Html::entities());

        Html::$entities = null;
    }

    public function testIsVoid(): void
    {
        $original = Html::$voidList;

        $this->assertTrue(Html::isVoid('hr'));
        $this->assertFalse(Html::isVoid('div'));
        $this->assertFalse(Html::isVoid(''));

        Html::$voidList[] = 'div';
        $this->assertTrue(Html::isVoid('div'));

        Html::$voidList = $original;
    }

    public function testTag(): void
    {
        $html = Html::tag('p', 'test');
        $expected = '<p>test</p>';
        $this->assertSame($expected, $html);

        $html = Html::tag('p', '');
        $expected = '<p></p>';
        $this->assertSame($expected, $html);

        $html = Html::tag('p', null);
        $expected = '<p></p>';
        $this->assertSame($expected, $html);

        $html = Html::tag('hr', '');
        $expected = '<hr>';
        $this->assertSame($expected, $html);

        $html = Html::tag('hr', null);
        $expected = '<hr>';
        $this->assertSame($expected, $html);

        Html::$void = ' />';
        $html = Html::tag('hr', null);
        $expected = '<hr />';
        $this->assertSame($expected, $html);
        Html::$void = '>';

        $html = Html::tag('p', 'test', ['class' => 'test']);
        $expected = '<p class="test">test</p>';
        $this->assertSame($expected, $html);

        $html = Html::tag('p', 'täst', ['class' => 'test']);
        $expected = '<p class="test">t&auml;st</p>';
        $this->assertSame($expected, $html);

        $html = Html::tag('p', ['<i>test</i>']);
        $expected = '<p><i>test</i></p>';
        $this->assertSame($expected, $html);
    }

    #[DataProvider('valueProvider')]
    public function testValue($input, $expected): void
    {
        $this->assertSame($expected, Html::value($input));
    }

    public static function valueProvider(): array
    {
        return [
            [true, 'true'],
            [false, 'false'],
            [1, '1'],
            [null, null],
            ['', null],
            ['test', 'test'],
            ['täst', 't&auml;st'],
        ];
    }
}
