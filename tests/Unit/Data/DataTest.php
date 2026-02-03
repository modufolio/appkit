<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Tests\Unit\Data;

use Modufolio\Appkit\Data\Data;
use Modufolio\Appkit\Data\Json;
use Modufolio\Appkit\Data\PHP;
use Modufolio\Appkit\Data\Txt;
use Modufolio\Appkit\Data\Xml;
use Modufolio\Appkit\Data\Yaml;
use Modufolio\Appkit\Toolkit\F;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

require_once __DIR__ . '/mocks.php';

#[CoversClass(Data::class)]
class DataTest extends TestCase
{
    public function testDefaultHandlers(): void
    {
        $this->assertInstanceOf(Json::class, Data::handler('json'));
        $this->assertInstanceOf(PHP::class, Data::handler('php'));
        $this->assertInstanceOf(Txt::class, Data::handler('txt'));
        $this->assertInstanceOf(Xml::class, Data::handler('xml'));
        $this->assertInstanceOf(Yaml::class, Data::handler('yaml'));

        // aliases
        $this->assertInstanceOf(Txt::class, Data::handler('md'));
        $this->assertInstanceOf(Txt::class, Data::handler('mdown'));
        $this->assertInstanceOf(Xml::class, Data::handler('rss'));
        $this->assertInstanceOf(Yaml::class, Data::handler('yml'));

        // different case
        $this->assertInstanceOf(Json::class, Data::handler('JSON'));
        $this->assertInstanceOf(Json::class, Data::handler('JsOn'));
    }

    public function testCustomHandler(): void
    {
        Data::$handlers['test'] = CustomHandler::class;
        $this->assertInstanceOf(CustomHandler::class, Data::handler('test'));
    }

    public function testCustomAlias(): void
    {
        Data::$aliases['plaintext'] = 'txt';
        $this->assertInstanceOf(Txt::class, Data::handler('plaintext'));
    }

    public function testMissingHandler(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Missing handler for type: "foo"');

        Data::handler('foo');
    }


    #[DataProvider('handlerProvider')]
    public function testEncodeDecode($handler): void
    {
        $data = [
            'name'  => 'Homer Simpson',
            'email' => 'homer@simpson.com'
        ];

        $encoded = Data::encode($data, $handler);
        $decoded = Data::decode($encoded, $handler);

        $this->assertSame($data, $decoded);
    }

    #[DataProvider('handlerProvider')]
    public function testDecodeInvalid1($handler): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ' . strtoupper($handler) . ' data; please pass a string');
        Data::decode(1, $handler);
    }

    #[DataProvider('handlerProvider')]
    public function testDecodeInvalid2($handler): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ' . strtoupper($handler) . ' data; please pass a string');
        Data::decode(new stdClass(), $handler);
    }

    #[DataProvider('handlerProvider')]
    public function testDecodeInvalid3($handler): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ' . strtoupper($handler) . ' data; please pass a string');
        Data::decode(true, $handler);
    }

    public static function handlerProvider(): array
    {
        $handlers = array_filter(
            array_keys(Data::$handlers),
            static fn ($handler) => $handler !== 'php'
        );

        return array_map(static fn ($handler) => [$handler], $handlers);
    }

    public function testReadWrite(): void
    {
        $data = [
            'name'  => 'Homer Simpson',
            'email' => 'homer@simpson.com'
        ];

        $file = __DIR__ . '/tmp/data.json';

        @unlink($file);

        Data::write($file, $data);
        $this->assertFileExists($file);
        $this->assertSame(Json::encode($data), F::read($file));

        $result = Data::read($file);
        $this->assertSame($data, $result);

        Data::write($file, $data, 'yml');
        $this->assertFileExists($file);
        $this->assertSame(Yaml::encode($data), F::read($file));

        $result = Data::read($file, 'yml');
        $this->assertSame($data, $result);
    }

    public function testReadInvalid(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Missing handler for type: "foo"');

        Data::read(__DIR__ . '/tmp/data.foo');
    }

    public function testWriteInvalid(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Missing handler for type: "foo"');

        $data = [
            'name'  => 'Homer Simpson',
            'email' => 'homer@simpson.com'
        ];

        Data::write(__DIR__ . '/tmp/data.foo', $data);
    }
}
