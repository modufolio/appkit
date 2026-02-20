<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\DependencyInjection;

use Modufolio\Appkit\DependencyInjection\ParameterBag;
use PHPUnit\Framework\TestCase;

class ParameterBagTest extends TestCase
{
    public function testConstructorWithParameters(): void
    {
        $params = ['db_host' => 'localhost', 'db_port' => '5432'];
        $bag = new ParameterBag($params);

        $this->assertTrue($bag->has('db_host'));
        $this->assertTrue($bag->has('db_port'));
        $this->assertSame('localhost', $bag->get('db_host'));
        $this->assertSame('5432', $bag->get('db_port'));
    }

    public function testAddParameters(): void
    {
        $bag = new ParameterBag();
        $bag->add(['app_name' => 'MyApp', 'debug' => 'true']);

        $this->assertTrue($bag->has('app_name'));
        $this->assertSame('MyApp', $bag->get('app_name'));
        $this->assertSame('true', $bag->get('debug'));
    }

    public function testSetParameter(): void
    {
        $bag = new ParameterBag();
        $bag->set('timeout', '30');

        $this->assertSame('30', $bag->get('timeout'));

        $bag->set('timeout', '60');
        $this->assertSame('60', $bag->get('timeout'));
    }

    public function testHasParameter(): void
    {
        $bag = new ParameterBag(['existing' => 'value']);

        $this->assertTrue($bag->has('existing'));
        $this->assertFalse($bag->has('nonexistent'));
    }

    public function testGetNonexistentParameterThrows(): void
    {
        $bag = new ParameterBag();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Parameter "missing" not found');

        $bag->get('missing');
    }

    public function testRemoveParameter(): void
    {
        $bag = new ParameterBag(['temp' => 'value']);

        $this->assertTrue($bag->has('temp'));
        $bag->remove('temp');
        $this->assertFalse($bag->has('temp'));
    }

    public function testClearAllParameters(): void
    {
        $bag = new ParameterBag(['a' => '1', 'b' => '2', 'c' => '3']);

        $this->assertCount(3, $bag->all());

        $bag->clear();

        $this->assertEmpty($bag->all());
        $this->assertFalse($bag->has('a'));
    }

    public function testGetAllParameters(): void
    {
        $params = ['key1' => 'value1', 'key2' => 'value2'];
        $bag = new ParameterBag($params);

        $all = $bag->all();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey('key1', $all);
        $this->assertArrayHasKey('key2', $all);
    }

    public function testParameterNameIsCaseInsensitive(): void
    {
        $bag = new ParameterBag(['MyParam' => 'value']);

        $this->assertTrue($bag->has('myparam'));
        $this->assertTrue($bag->has('MYPARAM'));
        $this->assertSame('value', $bag->get('MyParam'));
        $this->assertSame('value', $bag->get('MYPARAM'));
    }

    public function testSimplePlaceholderResolution(): void
    {
        $bag = new ParameterBag([
            'base_url' => 'https://example.com',
            'api_ref' => '%base_url%',
        ]);

        // Simple placeholder %name% should resolve
        $this->assertSame('https://example.com', $bag->get('api_ref'));
    }

    public function testParameterWithSpecialCharacters(): void
    {
        $bag = new ParameterBag([
            'connection_string' => 'Server=localhost;Port=5432;Database=mydb',
        ]);

        $this->assertSame('Server=localhost;Port=5432;Database=mydb', $bag->get('connection_string'));
    }
}
