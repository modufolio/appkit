<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Tests\Unit\Data;

use Modufolio\Appkit\Data\Xml;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @coversDefaultClass \Modufolio\Appkit\Data\Xml
 */
class XmlTest extends TestCase
{
    /**
     * @covers ::encode
     * @covers ::decode
     */
    public function testEncodeDecode(): void
    {
        $array = [
            'name'     => 'Homer',
            'children' => ['Lisa', 'Bart', 'Maggie']
        ];

        $expected = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<data>\n  <name>Homer</name>\n  " .
                    "<children>Lisa</children>\n  <children>Bart</children>\n  <children>Maggie</children>\n</data>";

        $data = Xml::encode($array);
        $this->assertSame($expected, $data);

        $result = Xml::decode($data);
        $this->assertSame($array, $result);

        // with a custom root name
        $expected = str_replace('data>', 'custom>', $expected);
        $array = [
            '@name'    => 'custom',
            'name'     => 'Homer',
            'children' => ['Lisa', 'Bart', 'Maggie']
        ];
        $result = Xml::decode($expected);
        $this->assertSame($array, $result);

        $this->assertSame([], Xml::decode(null));
        $this->assertSame(['this is' => 'an array'], Xml::decode(['this is' => 'an array']));
    }

    /**
     * @covers ::decode
     */
    public function testDecodeInvalid1(): void
    {
        // pass invalid object
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid XML data; please pass a string');
        Xml::decode(new stdClass());
    }

    /**
     * @covers ::decode
     */
    public function testDecodeInvalid2(): void
    {
        // pass invalid int
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid XML data; please pass a string');
        Xml::decode(1);
    }

    /**
     * @covers ::encode
     */
    public function testEncodeScalar(): void
    {
        $expected = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<data>test</data>';
        $this->assertSame($expected, Xml::encode('test'));
    }

    /**
     * @covers ::decode
     */
    public function testDecodeCorrupted(): void
    {
        $this->expectException('Exception');
        $this->expectExceptionMessage('XML string is invalid');

        Xml::decode('some gibberish');
    }
}
