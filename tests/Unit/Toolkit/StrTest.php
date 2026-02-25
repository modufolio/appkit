<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Tests\Unit\Toolkit;

use PHPUnit\Framework\Attributes\CoversClass;

use Modufolio\Appkit\Query\Query;
use Modufolio\Appkit\Toolkit\Html;
use Modufolio\Appkit\Toolkit\Str;
use IntlDateFormatter;
use PHPUnit\Framework\TestCase;

#[CoversClass(Str::class)]
class StrTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Str::$language = [];
    }


    public function testAscii(): void
    {
        $this->assertSame('aouss', Str::ascii('äöüß'));
        $this->assertSame('Istanbul', Str::ascii('İstanbul'));
        $this->assertSame('istanbul', Str::ascii('i̇stanbul'));
        $this->assertSame('Nashata istorija', Str::ascii('Нашата история'));
    }

    public function testAfter(): void
    {
        $string = 'Hellö Wörld';

        // case sensitive
        $this->assertSame(' Wörld', Str::after($string, 'ö'));
        $this->assertSame('', Str::after($string, 'Ö'));
        $this->assertSame('', Str::after($string, 'x'));

        // case insensitive
        $this->assertSame(' Wörld', Str::after($string, 'ö', true));
        $this->assertSame(' Wörld', Str::after($string, 'Ö', true));
        $this->assertSame('', Str::after($string, 'x', true));

        // non existing chars
        $this->assertSame('', Str::after('string', '.'), 'string with non-existing character should return false');
    }



    public function testBefore(): void
    {
        $string = 'Hellö Wörld';

        // case sensitive
        $this->assertSame('Hell', Str::before($string, 'ö'));
        $this->assertSame('', Str::before($string, 'Ö'));
        $this->assertSame('', Str::before($string, 'x'));

        // case insensitive
        $this->assertSame('Hell', Str::before($string, 'ö', true));
        $this->assertSame('Hell', Str::before($string, 'Ö', true));
        $this->assertSame('', Str::before($string, 'x', true));
    }


    public function testBetween(): void
    {
        $this->assertSame('trin', Str::between('string', 's', 'g'), 'string between s and g should be trin');
        $this->assertSame('', Str::between('string', 's', '.'), 'function with non-existing character should return false');
        $this->assertSame('', Str::between('string', '.', 'g'), 'function with non-existing character should return false');
    }

    public function testCamel(): void
    {
        $string = 'foo_bar';
        $this->assertSame('fooBar', Str::camel($string));

        $string = 'FòôBàř';
        $this->assertSame('fòôBàř', Str::camel($string));

        $string = 'Fòô-bàřBaz';
        $this->assertSame('fòôBàřBaz', Str::camel($string));

        $string = 'Fòô-bàř_Baz';
        $this->assertSame('fòôBàřBaz', Str::camel($string));

        $string = 'fòô_bàř';
        $this->assertSame('fòôBàř', Str::camel($string));
    }

    public function testClean(): void
    {
        $input = "   This is\n some\n  text with\n line breaks  \n";
        $expectedOutput = 'This is some text with line breaks';

        $this->assertEquals($expectedOutput, Str::clean($input));
    }

    public function testContains(): void
    {
        $string = 'Hellö Wörld';

        // case sensitive
        $this->assertTrue(Str::contains($string, 'Hellö'));
        $this->assertTrue(Str::contains($string, 'Wörld'));
        $this->assertFalse(Str::contains($string, 'hellö'));
        $this->assertFalse(Str::contains($string, 'wörld'));

        // case insensitive
        $this->assertTrue(Str::contains($string, 'Hellö', true));
        $this->assertTrue(Str::contains($string, 'Wörld', true));
        $this->assertTrue(Str::contains($string, 'hellö', true));
        $this->assertTrue(Str::contains($string, 'wörld', true));

        // empty needle
        $this->assertTrue(Str::contains($string, ''));
    }

    public function testConvert(): void
    {
        $source = 'ÖÄÜ';

        // same encoding
        $result = Str::convert($source, 'UTF-8');
        $this->assertSame('UTF-8', Str::encoding($source));
        $this->assertSame('UTF-8', Str::encoding($result));

        // different  encoding
        $result = Str::convert($source, 'ISO-8859-1');
        $this->assertSame('UTF-8', Str::encoding($source));
        $this->assertSame('ISO-8859-1', Str::encoding($result));
    }

    public function testDate(): void
    {
        $time = mktime(1, 1, 1, 1, 29, 2020);

        // default `date` handler
        $this->assertSame($time, Str::date($time));
        $this->assertSame('29.01.2020', Str::date($time, 'd.m.Y'));

        // `intl` handler
        // $formatter = new IntlDateFormatter('en-US', IntlDateFormatter::LONG, IntlDateFormatter::SHORT);
        $this->assertSame($time, Str::date($time, null, 'intl'));
        $this->assertSame('29/1/2020 01:01', Str::date($time, 'd/M/yyyy HH:mm', 'intl'));
        //$this->assertSame('January 29, 2020 at 1:01 AM', Str::date($time, $formatter));
    }

    public function testEncode(): void
    {
        $email = 'test@getkirby.com';
        $this->assertSame($email, Html::decode(Str::encode($email)));
    }

    public function testEncoding(): void
    {
        $this->assertSame('UTF-8', Str::encoding('ÖÄÜ'));
    }

    public function testEndsWith(): void
    {
        $string = 'Hellö Wörld';

        // case sensitive
        $this->assertTrue(Str::endsWith($string, ''));
        $this->assertTrue(Str::endsWith($string, 'd'));
        $this->assertFalse(Str::endsWith($string, 'D'));
        $this->assertTrue(Str::endsWith($string, 'Wörld'));
        $this->assertFalse(Str::endsWith($string, 'WÖRLD'));

        // case insensitive
        $this->assertTrue(Str::endsWith($string, '', true));
        $this->assertTrue(Str::endsWith($string, 'd', true));
        $this->assertTrue(Str::endsWith($string, 'D', true));
        $this->assertTrue(Str::endsWith($string, 'Wörld', true));
        $this->assertTrue(Str::endsWith($string, 'WÖRLD', true));
    }

    public function testExcerpt(): void
    {
        $string   = 'This is a long text<br>with some html';
        $expected = 'This is a long text with …';
        $result   = Str::excerpt($string, 27);

        $this->assertSame($expected, $result);
    }

    public function testExcerptWithoutChars(): void
    {
        $string   = 'This is a long text<br>with some html';
        $expected = 'This is a long text with some html';
        $result   = Str::excerpt($string);

        $this->assertSame($expected, $result);
    }

    public function testExcerptWithZeroLength(): void
    {
        $string = 'This is a long text with some html';
        $result = Str::excerpt($string, 0);

        $this->assertSame($string, $result);
    }

    public function testExcerptWithoutStripping(): void
    {
        $string   = 'This is a long text<br>with some html';
        $expected = 'This is a long text<br>with …';
        $result   = Str::excerpt($string, 30, false);

        $this->assertSame($expected, $result);
    }

    public function testExcerptWithDifferentRep(): void
    {
        $string   = 'This is a long text<br>with some html';
        $expected = 'This is a long text with ...';
        $result   = Str::excerpt($string, 27, true, ' ...');

        $this->assertSame($expected, $result);
    }

    public function testExcerptWithSpaces(): void
    {
        $string   = 'This is a long text   <br>with some html';
        $expected = 'This is a long text with …';
        $result   = Str::excerpt($string, 27);

        $this->assertSame($expected, $result);
    }

    public function testExcerptWithLineBreaks(): void
    {
        $string   = 'This is a long text ' . PHP_EOL . ' with some html';
        $expected = 'This is a long text with …';
        $result   = Str::excerpt($string, 27);

        $this->assertSame($expected, $result);
    }

    public function testExcerptWithUnicodeChars(): void
    {
        $string   = 'Hellö Wörld text<br>with söme htmäl';
        $expected = 'Hellö Wörld text …';
        $result   = Str::excerpt($string, 20);

        $this->assertSame($expected, $result);
    }

    public function testExcerptWithTagFollowedByInterpunctuation(): void
    {
        $string   = 'Why not <a href="https://getkirby.com/">Get Kirby</a>?';
        $expected = 'Why not Get Kirby?';
        $result   = Str::excerpt($string, 100);

        $this->assertSame($expected, $result);
    }


    public function testFrom(): void
    {
        $string = 'Hellö Wörld';

        // case sensitive
        $this->assertSame('ö Wörld', Str::from($string, 'ö'));
        $this->assertSame('', Str::from($string, 'Ö'));
        $this->assertSame('', Str::from($string, 'x'));

        // case insensitive
        $this->assertSame('ö Wörld', Str::from($string, 'ö', true));
        $this->assertSame('ö Wörld', Str::from($string, 'Ö', true));
        $this->assertSame('', Str::from($string, 'x'));
    }

    public function testGetType(): void
    {
        $this->assertEquals('int', Str::getType('123'));
        $this->assertEquals('float', Str::getType('3.14'));
        $this->assertEquals('bool', Str::getType('true'));
        $this->assertEquals('bool', Str::getType('false'));
        $this->assertEquals('null', Str::getType('null'));
        $this->assertEquals('string', Str::getType('Hello, world!'));
        $this->assertEquals('string', Str::getType('123abc'));
    }


    public function testIncrement(): void
    {
        $string = 'Pöst';
        $this->assertSame('Pöst-1', Str::increment($string));

        $string = 'Pöst-1';
        $this->assertSame('Pöst-2', Str::increment($string));

        $string = 'Pöst-2';
        $this->assertSame('Pöst-3', Str::increment($string));

        $string = 'Pöst';
        $this->assertSame('Pöst_1', Str::increment($string, '_'));

        $string = 'Pöst';
        $this->assertSame('Pöst_10', Str::increment($string, '_', 10));

        $string = 'Pöst_10';
        $this->assertSame('Pöst_11', Str::increment($string, '_', 1));

        $string = 'Pöst_10';
        $this->assertSame('Pöst_11', Str::increment($string, '_', 10));

        $string = 'Pöst';
        $this->assertSame('Pöst 1', Str::increment($string, ' ', 1));

        $string = 'Pöst post 1';
        $this->assertSame('Pöst post 2', Str::increment($string, ' ', 1));

        $string = 'Pöst_10';
        $this->assertSame('Pöst_10-1', Str::increment($string, '-'));

        $string = 'Pöst-10';
        $this->assertSame('Pöst-10_1', Str::increment($string, '_'));

        $string = 'Pöst-5';
        $this->assertSame('Pöst-6', Str::increment($string, '-', 10));

        $string = 'Pöst-15';
        $this->assertSame('Pöst-16', Str::increment($string, '-', 10));
    }

    public function testKebab(): void
    {
        $string = 'KingCobra';
        $this->assertSame('king-cobra', Str::kebab($string));

        $string = 'kingCobra';
        $this->assertSame('king-cobra', Str::kebab($string));
    }

    public function testLength(): void
    {
        $this->assertSame(0, Str::length(''));
        $this->assertSame(3, Str::length('abc'));
        $this->assertSame(3, Str::length('öäü'));
        $this->assertSame(6, Str::length('Aœ?_ßö'));
    }

    public function testLower(): void
    {
        $this->assertSame('öäü', Str::lower('ÖÄÜ'));
        $this->assertSame('öäü', Str::lower('Öäü'));
    }

    public function testLtrim(): void
    {
        $this->assertSame('test', Str::ltrim(' test'));
        $this->assertSame('test', Str::ltrim('  test'));
        $this->assertSame('jpg', Str::ltrim('test.jpg', 'test.'));
    }

    public function testMatch(): void
    {
        $this->assertSame(['test', 'es'], Str::match('test', '/t(es)t/'));
        $this->assertNull(Str::match('one two three', '/(four)/'));
    }


    public function testMatches(): void
    {
        $this->assertTrue(Str::matches('test', '/t(es)t/'));
        $this->assertFalse(Str::matches('one two three', '/(four)/'));
    }

    public function testMatchAll(): void
    {
        $longText = <<<TEXT
		This is line with "one" and something else to match.
		This is line with "two" and another thing to match.
		This is line with "three" and yet another match.
		TEXT;

        $matches = Str::matchAll($longText, '/"(.*)" and (.*).$/m');

        $this->assertSame(['one', 'two', 'three'], $matches[1]);
        $this->assertSame(['something else to match', 'another thing to match', 'yet another match'], $matches[2]);
        $this->assertNull(Str::matchAll($longText, '/(miao)/'));
        $this->assertNull(Str::matchAll('one two three', '/(four)/'));
    }


    public function testPosition(): void
    {
        $string = 'Hellö Wörld';

        // case sensitive
        $this->assertTrue(Str::position($string, 'H') === 0);
        $this->assertFalse(Str::position($string, 'h') === 0);
        $this->assertTrue(Str::position($string, 'ö') === 4);
        $this->assertFalse(Str::position($string, 'Ö') === 4);

        // case insensitive
        $this->assertTrue(Str::position($string, 'H', true) === 0);
        $this->assertTrue(Str::position($string, 'h', true) === 0);
        $this->assertTrue(Str::position($string, 'ö', true) === 4);
        $this->assertTrue(Str::position($string, 'Ö', true) === 4);
    }


    public function testQuery(): void
    {
        $result = Str::query('data.1', ['data' => ['foo', 'bar']]);
        $this->assertSame('bar', $result);
    }

    public function testRandom(): void
    {
        // choose a high length for a high probability of occurrence of a character of any type
        $length = 200;

        $this->assertMatchesRegularExpression('/^[[:alnum:]]+$/', Str::random());
        $this->assertIsString(Str::random());
        $this->assertSame($length, strlen(Str::random($length)));

        $this->assertMatchesRegularExpression('/^[[:alpha:]]+$/', Str::random($length, 'alpha'));

        $this->assertMatchesRegularExpression('/^[[:upper:]]+$/', Str::random($length, 'alphaUpper'));

        $this->assertMatchesRegularExpression('/^[[:lower:]]+$/', Str::random($length, 'alphaLower'));

        $this->assertMatchesRegularExpression('/^[[:digit:]]+$/', Str::random($length, 'num'));

        $this->assertFalse(Str::random($length, 'something invalid'));
    }

    public function testReplaceInvalid3(): void
    {
        $this->expectException('TypeError');

        Str::replace('some string', ['some', 'string'], 'other string', [1, 'string']);
    }

    public function testReplacements(): void
    {
        // simple example
        $this->assertSame([
            ['search' => 'a', 'replace' => 'b', 'limit' => 2]
        ], Str::replacements('a', 'b', 2));

        // multiple searches
        $this->assertSame([
            ['search' => 'a', 'replace' => 'c', 'limit' => 2],
            ['search' => 'b', 'replace' => 'c', 'limit' => 2]
        ], Str::replacements(['a', 'b'], 'c', 2));

        // multiple replacements
        $this->assertSame([
            ['search' => 'a', 'replace' => 'c', 'limit' => 2],
            ['search' => 'b', 'replace' => 'd', 'limit' => 2]
        ], Str::replacements(['a', 'b'], ['c', 'd'], 2));

        $this->assertSame([
            ['search' => 'a', 'replace' => 'c', 'limit' => 2],
            ['search' => 'b', 'replace' => '', 'limit' => 2]
        ], Str::replacements(['a', 'b'], ['c'], 2));

        // multiple limits
        $this->assertSame([
            ['search' => 'a', 'replace' => 'c', 'limit' => 2],
            ['search' => 'b', 'replace' => 'c', 'limit' => 1]
        ], Str::replacements(['a', 'b'], 'c', [2, 1]));

        $this->assertSame([
            ['search' => 'a', 'replace' => 'c', 'limit' => 2],
            ['search' => 'b', 'replace' => 'd', 'limit' => 1]
        ], Str::replacements(['a', 'b'], ['c', 'd'], [2, 1]));

        $this->assertSame([
            ['search' => 'a', 'replace' => 'c', 'limit' => 2],
            ['search' => 'b', 'replace' => 'd', 'limit' => -1]
        ], Str::replacements(['a', 'b'], ['c', 'd'], [2]));
    }

    public function testReplacementsInvalid(): void
    {
        $this->expectException('Exception');

        Str::replacements('string', ['array'], 1);
    }

    public function testReplaceReplacements(): void
    {
        $this->assertSame(
            'other other string',
            Str::replaceReplacements('some some string', [
                [
                    'search'  => 'some',
                    'replace' => 'other',
                    'limit'   => -1
                ]
            ])
        );

        $this->assertSame(
            'other interesting story',
            Str::replaceReplacements('some some string', [
                [
                    'search'  => 'some',
                    'replace' => 'other',
                    'limit'   => -1
                ],
                [
                    'search'  => 'other string',
                    'replace' => 'interesting string',
                    'limit'   => 1
                ],
                [
                    'search'  => 'string',
                    'replace' => 'story',
                    'limit'   => 5
                ]
            ])
        );

        // edge cases are tested in the Str::replace() unit test
    }

    public function testReplaceReplacementsInvalid(): void
    {
        $this->expectException('Exception');

        Str::replaceReplacements('some string', [
            [
                'search'  => 'some',
                'replace' => 'other',
                'limit'   => 'string'
            ]
        ]);
    }

    public function testReverse(): void
    {
        $this->assertEquals('cba', Str::reverse('abc'));
        $this->assertEquals('racecar', Str::reverse('racecar'));
        $this->assertEquals('54321', Str::reverse('12345'));
        $this->assertEquals('😀😃😄', Str::reverse('😄😃😀'));
    }

    public function testRtrim(): void
    {
        $this->assertSame('test', Str::rtrim('test '));
        $this->assertSame('test', Str::rtrim('test  '));
        $this->assertSame('test', Str::rtrim('test.jpg', '.jpg'));
    }

    public function testShort(): void
    {
        $string = 'Super Äwesøme String';

        // too long
        $this->assertSame('Super…', Str::short($string, 5));

        // not too long
        $this->assertSame($string, Str::short($string, 100));

        // zero chars
        $this->assertSame($string, Str::short($string));

        // with different ellipsis character
        $this->assertSame('Super---', Str::short($string, 5, '---'));

        // with number
        $this->assertSame('12345', Str::short('12345', 5));

        // with long number
        $this->assertSame('12345…', Str::short('123456', 5));
    }

    public function testSimilarity(): void
    {
        $this->assertSame([
            'matches' => 0,
            'percent' => 0.0
        ], Str::similarity('foo', 'bar'));

        $this->assertSame([
            'matches' => 0,
            'percent' => 0.0
        ], Str::similarity('foo', ''));

        $this->assertSame([
            'matches' => 0,
            'percent' => 0.0
        ], Str::similarity('', 'foo'));

        $this->assertSame([
            'matches' => 0,
            'percent' => 0.0
        ], Str::similarity('', ''));

        $this->assertSame([
            'matches' => 3,
            'percent' => 66.66666666666667
        ], Str::similarity('foo', 'fooBar'));

        $this->assertSame([
            'matches' => 3,
            'percent' => 100.0
        ], Str::similarity('foo', 'foo'));

        $this->assertSame([
            'matches' => 4,
            'percent' => 100.0
        ], Str::similarity('tête', 'tête'));

        $this->assertSame([
            'matches' => 3,
            'percent' => 75.0
        ], Str::similarity('Tête', 'tête'));

        $this->assertSame([
            'matches' => 0,
            'percent' => 0.0
        ], Str::similarity('foo', 'FOO'));

        $this->assertSame([
            'matches' => 1,
            'percent' => 20.0
        ], Str::similarity('Kirby', 'KIRBY'));

        // case-insensitive
        $this->assertSame([
            'matches' => 4,
            'percent' => 100.0
        ], Str::similarity('Tête', 'tête', true));

        $this->assertSame([
            'matches' => 2,
            'percent' => 66.66666666666667
        ], Str::similarity('foo', 'FOU', true));

        $this->assertSame([
            'matches' => 5,
            'percent' => 100.0
        ], Str::similarity('Kirby', 'KIRBY', true));
    }

    public function testSlug(): void
    {
        // Double dashes
        $this->assertSame('a-b', Str::slug('a--b'));

        // Dashes at the end of the line
        $this->assertSame('a', Str::slug('a-'));

        // Dashes at the beginning of the line
        $this->assertSame('a', Str::slug('-a'));

        // Underscores converted to dashes
        $this->assertSame('a-b', Str::slug('a_b'));

        // Unallowed characters
        $this->assertSame('a-b', Str::slug('a@b'));

        // Spaces characters
        $this->assertSame('a-b', Str::slug('a b'));

        // Double Spaces characters
        $this->assertSame('a-b', Str::slug('a  b'));

        // Custom separator
        $this->assertSame('a+b', Str::slug('a-b', '+'));

        // Allow underscores
        $this->assertSame('a_b', Str::slug('a_b', '-', 'a-z0-9_'));

        // store default defaults
        $defaults = Str::$defaults['slug'];

        // Custom str defaults
        Str::$defaults['slug']['separator'] = '+';
        Str::$defaults['slug']['allowed']   = 'a-z0-9_';

        $this->assertSame('a+b', Str::slug('a-b'));
        $this->assertSame('a_b', Str::slug('a_b'));

        // Reset str defaults
        Str::$defaults['slug'] = $defaults;

        // Language rules
        Str::$language = ['ä' => 'ae'];
        $this->assertSame('ae-b', Str::slug('ä b'));
        Str::$language = [];
    }

    public function testSnake(): void
    {
        $string = 'KingCobra';
        $this->assertSame('king_cobra', Str::snake($string));

        $string = 'kingCobra';
        $this->assertSame('king_cobra', Str::snake($string));
    }

    public function testSplit(): void
    {
        // default separator
        $string = 'ä,ö,ü,ß';
        $this->assertSame(['ä', 'ö', 'ü', 'ß'], Str::split($string));

        // custom separator
        $string = 'ä/ö/ü/ß';
        $this->assertSame(['ä', 'ö', 'ü', 'ß'], Str::split($string, '/'));

        // custom separator and limited length
        $string = 'ää/ö/üü/ß';
        $this->assertSame(['ää', 'üü'], Str::split($string, '/', 2));

        // custom separator with line-breaks
        $string = <<<EOT
            ---
            -abc-
            ---
            -def-
EOT;
        $this->assertSame(['-abc-', '-def-'], Str::split($string, '---'));
    }

    public function testStartsWith(): void
    {
        $string = 'Hellö Wörld';

        // case sensitive
        $this->assertTrue(Str::startsWith($string, ''));
        $this->assertTrue(Str::startsWith($string, 'H'));
        $this->assertFalse(Str::startsWith($string, 'h'));
        $this->assertTrue(Str::startsWith($string, 'Hellö'));
        $this->assertFalse(Str::startsWith($string, 'hellö'));

        // case insensitive
        $this->assertTrue(Str::startsWith($string, '', true));
        $this->assertTrue(Str::startsWith($string, 'H', true));
        $this->assertTrue(Str::startsWith($string, 'h', true));
        $this->assertTrue(Str::startsWith($string, 'Hellö', true));
        $this->assertTrue(Str::startsWith($string, 'hellö', true));
    }

    public function testStudly(): void
    {
        $string = 'foo_bar';
        $this->assertSame('FooBar', Str::studly($string));

        $string = 'FòôBàř';
        $this->assertSame('FòôBàř', Str::studly($string));

        $string = 'Fòô-bàřBaz';
        $this->assertSame('FòôBàřBaz', Str::studly($string));

        $string = 'Fòô-bàř_Baz';
        $this->assertSame('FòôBàřBaz', Str::studly($string));

        $string = 'fòô_bàř';
        $this->assertSame('FòôBàř', Str::studly($string));
    }

    public function testSubstr(): void
    {
        $string = 'äöü';

        $this->assertSame($string, Str::substr($string));
        $this->assertSame($string, Str::substr($string, 0));
        $this->assertSame($string, Str::substr($string, 0, 3));
        $this->assertSame('ä', Str::substr($string, 0, 1));
        $this->assertSame('äö', Str::substr($string, 0, 2));
        $this->assertSame('ü', Str::substr($string, -1));
    }

    public function testTemplate(): void
    {
        // query with a string
        $string = 'From {{ b }} to {{ a }}';
        $this->assertSame('From here to there', Str::template($string, ['a' => 'there', 'b' => 'here']));
        $this->assertSame('From {{ b }} to {{ a }}', Str::template($string, []));
        $this->assertSame('From here to {{ a }}', Str::template($string, ['b' => 'here']));
        $this->assertSame('From here to {{ a }}', Str::template($string, ['a' => null, 'b' => 'here']));
        $this->assertSame('From - to -', Str::template($string, [], ['fallback' => '-']));
        $this->assertSame('From  to ', Str::template($string, [], ['fallback' => '']));
        $this->assertSame('From here to -', Str::template($string, ['b' => 'here'], ['fallback' => '-']));

        // query with an array
        $template = Str::template('Hello {{ user.username }}', [
            'user' => [
                'username' => 'homer'
            ]
        ]);
        $this->assertSame('Hello homer', $template);

        $template = Str::template('{{ user.greeting }} {{ user.username }}', [
            'user' => [
                'username' => 'homer'
            ]
        ]);
        $this->assertSame('{{ user.greeting }} homer', $template);

        // query with an array and callback
        $template = Str::template('Hello {{ user.username }}', [
            'user' => [
                'username' => 'homer'
            ]
        ], [
            'callback' => fn ($result) => Str::ucfirst($result)
        ]);
        $this->assertSame('Hello Homer', $template);

    }

    public function testToBytes(): void
    {
        $this->assertSame(0, Str::toBytes(''));
        $this->assertSame(0, Str::toBytes('x'));
        $this->assertSame(0, Str::toBytes('K'));
        $this->assertSame(0, Str::toBytes('M'));
        $this->assertSame(0, Str::toBytes('G'));
        $this->assertSame(2, Str::toBytes('2'));
        $this->assertSame(2 * 1024, Str::toBytes('2K'));
        $this->assertSame(2 * 1024, Str::toBytes('2k'));
        $this->assertSame(2 * 1024 * 1024, Str::toBytes('2M'));
        $this->assertSame(2 * 1024 * 1024, Str::toBytes('2m'));
        $this->assertSame(2 * 1024 * 1024 * 1024, Str::toBytes('2G'));
        $this->assertSame(2 * 1024 * 1024 * 1024, Str::toBytes('2g'));
    }

    public function testToType(): void
    {
        // string to string
        $this->assertSame('a', Str::toType('a', 'string'));

        // string to array
        $this->assertSame(['a'], Str::toType('a', 'array'));
        $this->assertSame(['a'], Str::toType('a', []));

        // string to bool
        $this->assertTrue(Str::toType('true', 'bool'));
        $this->assertTrue(Str::toType('true', 'boolean'));
        $this->assertTrue(Str::toType('1', 'bool'));
        $this->assertTrue(Str::toType('1', true));
        $this->assertFalse(Str::toType('false', 'bool'));
        $this->assertFalse(Str::toType('false', 'boolean'));
        $this->assertFalse(Str::toType('0', 'bool'));
        $this->assertFalse(Str::toType('0', false));

        // string to float
        $this->assertSame(1.1, Str::toType('1.1', 'float'));
        $this->assertSame(1.1, Str::toType('1.1', 'double'));
        $this->assertSame(1.1, Str::toType('1.1', 1.1));

        // string to int
        $this->assertSame(1, Str::toType('1', 'int'));
        $this->assertSame(1, Str::toType('1', 'integer'));
        $this->assertSame(1, Str::toType('1', 1));
    }

    public function testTrim(): void
    {
        $this->assertSame('test', Str::trim(' test '));
        $this->assertSame('test', Str::trim('  test  '));
        $this->assertSame('test', Str::trim('.test.', '.'));
    }

    public function testUcfirst(): void
    {
        $this->assertSame('Hello world', Str::ucfirst('hello world'));
        $this->assertSame('Hello world', Str::ucfirst('Hello World'));
    }

    public function testUcwords(): void
    {
        $this->assertSame('Hello World', Str::ucwords('hello world'));
        $this->assertSame('Hello World', Str::ucwords('Hello world'));
        $this->assertSame('Hello World', Str::ucwords('HELLO WORLD'));
    }

    public function testUnhtml(): void
    {
        $string = 'some <em>crazy</em> stuff';
        $this->assertSame('some crazy stuff', Str::unhtml($string));
    }

    public function testUntil(): void
    {
        $string = 'Hellö Wörld';

        // case sensitive
        $this->assertSame('Hellö', Str::until($string, 'ö'));
        $this->assertSame('', Str::until($string, 'Ö'));
        $this->assertSame('', Str::until($string, 'x'));

        // case insensitive
        $this->assertSame('Hellö', Str::until($string, 'ö', true));
        $this->assertSame('Hellö', Str::until($string, 'Ö', true));
        $this->assertSame('', Str::until($string, 'x'));
    }

    public function testUpper(): void
    {
        $this->assertSame('ÖÄÜ', Str::upper('öäü'));
        $this->assertSame('ÖÄÜ', Str::upper('Öäü'));
    }

    public function testWordCount(): void
    {
        $this->assertEquals(4, Str::wordCount('This is a test'));
        $this->assertEquals(9, Str::wordCount('The quick brown fox jumps over the lazy dog'));
        $this->assertEquals(0, Str::wordCount(''));
        $this->assertEquals(1, Str::wordCount('Hello'));
        $this->assertEquals(4, Str::wordCount('This  is  a  test')); // test double spaces
        $this->assertEquals(2, Str::wordCount("I'm sorry")); // test punctuation
    }
    public function testWords(): void
    {
        $longString = 'Why do programmers prefer dark mode? Because light attracts bugs. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Mauris et felis lacus. Suspendisse eget interdum velit, vel laoreet velit. Fusce nec enim vitae ex dictum sagittis. Integer mollis aliquam lacinia. Proin faucibus justo nisl, id pretium sem rhoncus eget. Morbi eu enim id elit scelerisque suscipit eu in eros. Duis bibendum, velit in ultricies vulputate, libero sapien eleifend purus, non facilisis metus ipsum eu risus. Nulla commodo sit amet tortor id aliquam. Why don’t scientists trust atoms? Because they make up everything.';

        $this->assertEquals('Why do programmers prefer dark mode? Because light attracts bugs. Lorem ipsum', Str::words($longString, 12, ''));
        $this->assertEquals($longString, Str::words($longString, 200));
        $this->assertEquals('Why do programmers prefer dark mode? Because light attracts bugs. Lorem ipsum...', Str::words($longString, 12, '...'));
        $this->assertEquals('Why do programmers prefer', Str::words($longString, 4, ''));
        $this->assertEquals('Why do programmers prefer dark mode? Because light attracts bugs. Lorem ipsum dolor', Str::words($longString, 13, ''));
    }

    public function testWidont(): void
    {
        $this->assertSame('Test', Str::widont('Test'));
        $this->assertSame('Test?', Str::widont('Test?'));
        $this->assertSame('Test&nbsp;?', Str::widont('Test ?'));
        $this->assertSame('Test&nbsp;Headline', Str::widont('Test Headline'));
        $this->assertSame('Test Headline&nbsp;With&#8209;Dash', Str::widont('Test Headline With-Dash'));
        $this->assertSame('Test Headline&nbsp;With&#8209;Dash&nbsp;?', Str::widont('Test Headline With-Dash ?'));
        $this->assertSame('Omelette du&nbsp;fromage', Str::widont('Omelette du fromage'));
        $this->assertSame('Omelette du&nbsp;fromage.', Str::widont('Omelette du fromage.'));
        $this->assertSame('Omelette du&nbsp;fromage?', Str::widont('Omelette du fromage?'));
        $this->assertSame('Omelette du&nbsp;fromage&nbsp;?', Str::widont('Omelette du fromage ?'));
    }
}
