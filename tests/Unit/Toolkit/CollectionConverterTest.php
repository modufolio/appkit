<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Toolkit;

use Modufolio\Appkit\Toolkit\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Collection::class)]
class CollectionConverterTest extends TestCase
{
    public function testToArray()
    {
        $array = [
            'one' => 'eins',
            'two' => 'zwei',
        ];
        $collection = new Collection($array);
        $this->assertSame($array, $collection->toArray());
    }

    public function testToArrayMap()
    {
        $collection = new Collection([
            'one' => 'eins',
            'two' => 'zwei',
        ]);
        $this->assertSame([
            'one' => 'einsy',
            'two' => 'zweiy',
        ], $collection->toArray(function ($item) {
            return $item.'y';
        }));
    }

    public function testToJson()
    {
        $collection = new Collection([
            'one' => 'eins',
            'two' => 'zwei',
        ]);
        $this->assertSame('{"one":"eins","two":"zwei"}', $collection->toJson());
    }

    public function testToString()
    {
        $collection = new Collection([
            'one' => 'eins',
            'two' => 'zwei',
        ]);
        $string = 'one<br />two';
        $this->assertSame($string, $collection->toString());
        $this->assertSame($string, (string) $collection);
    }

    public function testJoin(): void
    {
        $collection = new Collection(['a', 'b', 'c']);

        $this->assertSame('a, b, c', $collection->join());
        $this->assertSame('a-b-c', $collection->join('-'));
        $this->assertSame('A|B|C', $collection->join('|', fn ($item) => strtoupper($item)));
    }

    public function testValues(): void
    {
        $collection = new Collection(['a' => 1, 'b' => 2]);

        $this->assertSame([1, 2], $collection->values());
        $this->assertSame([2, 4], $collection->values(fn ($value) => $value * 2));
    }
}
