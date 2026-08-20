<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Query;

use Modufolio\Appkit\Query\Segment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MyObj
{
    public string $homer = 'simpson';

    public function foo(int $count): string
    {
        return $count.'bar';
    }
}

class MyCallObj
{
    /**
     * @param list<mixed> $args
     */
    public function __call(string $name, array $args): string
    {
        return $args[0].'bar';
    }
}

class MyGetObj
{
    public function __get(string $name): string
    {
        return 'simpson';
    }
}

#[CoversClass(Segment::class)]
class SegmentTest extends TestCase
{
    /**
     * @return list<array<int, mixed>>
     */
    public static function scalarProvider(): array
    {
        return [
            ['test', 'string'],
            [1, 'integer'],
            [1.1, 'float'],
            [true, 'boolean'],
            [false, 'boolean'],
            [null, 'null'],
        ];
    }

    #[DataProvider('scalarProvider')]
    public function testErrorWithScalars(mixed $scalar, string $label): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Access to method "foo" on '.$label);

        Segment::error($scalar, 'foo', 'method');
    }

    public function testErrorWithObject(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Access to non-existing method "foo" on object');

        Segment::error(new \stdClass(), 'foo', 'method');
    }

    public function testFactory(): void
    {
        $segment = Segment::factory('foo');
        $this->assertSame('foo', $segment->method);
        $this->assertNull($segment->arguments);

        $segment = Segment::factory('foo(1, 2)');
        $this->assertSame('foo', $segment->method);
        $this->assertNotNull($segment->arguments);
        $this->assertCount(2, $segment->arguments);

        $segment = Segment::factory('foo(1, bar(2))');
        $this->assertSame('foo', $segment->method);
        $this->assertNotNull($segment->arguments);
        $this->assertCount(2, $segment->arguments);
    }

    public function testResolveFirst(): void
    {
        // without parameters
        $segment = Segment::factory('foo');
        $this->assertSame('bar', $segment->resolve(null, ['foo' => 'bar']));

        // with parameters
        $segment = Segment::factory('foo(2, "bar")');
        $this->assertSame('2bar', $segment->resolve(null, ['foo' => fn (int $a, string $b) => $a.$b]));
    }

    public function testResolveFirstWithDataObject(): void
    {
        $obj = new \stdClass();
        $obj->foo = 'bar';
        $segment = Segment::factory('foo');
        $this->assertSame('bar', $segment->resolve(null, $obj));
    }

    public function testResolveArray(): void
    {
        $segment = Segment::factory('foo', 1);
        $data = ['foo' => $expected = [1, 2]];
        $this->assertSame($expected, $segment->resolve($data));
    }

    public function testResolveArrayClosure(): void
    {
        $segment = Segment::factory('foo', 0);
        $data = ['foo' => fn () => 'bar'];
        $this->assertSame('bar', $segment->resolve(null, $data));
    }

    public function testResolveArrayInvalidKey(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Access to non-existing property "foo" on array');

        $segment = Segment::factory('foo');
        $segment->resolve(['bar' => 2]);
    }

    public function testResolveObject(): void
    {
        $obj = new MyObj();
        $segment = Segment::factory('foo(2)', 1);
        $this->assertSame('2bar', $segment->resolve($obj));

        $obj = new MyObj();
        $segment = Segment::factory('homer', 1);
        $this->assertSame('simpson', $segment->resolve($obj));

        $obj = new MyCallObj();
        $segment = Segment::factory('foo(2)', 1);
        $this->assertSame('2bar', $segment->resolve($obj));

        $obj = new MyGetObj();
        $segment = Segment::factory('homer', 1);
        $this->assertSame('simpson', $segment->resolve($obj));
    }

    public function testResolveObjectInvalid(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Access to method/property "foo" on string');

        $segment = Segment::factory('foo', 1);
        $segment->resolve('bar');
    }

    public function testResolveObjectInvalidMethod(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Access to non-existing method/property "notfound" on object');

        $obj = new MyObj();
        $segment = Segment::factory('notfound', 1);
        $segment->resolve($obj);
    }
}
