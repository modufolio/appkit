<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Toolkit;

use Modufolio\Appkit\Toolkit\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Collection::class)]
class CollectionGetterTest extends TestCase
{
    public function testGetMagic(): void
    {
        $collection = new Collection([
            'one' => 'eins',
            'two' => 'zwei',
        ]);

        $this->assertSame('eins', $collection->one);
        $this->assertNull($collection->three);
    }

    public function testGet(): void
    {
        $collection = new Collection([
            'one' => 'eins',
            'two' => 'zwei',
        ]);

        $this->assertSame('eins', $collection->get('one'));
        $this->assertNull($collection->get('three'));
        $this->assertSame('default', $collection->get('three', 'default'));
    }

    public function testMagicMethods(): void
    {
        $collection = new Collection([
            'one' => 'eins',
            'two' => 'zwei',
        ]);

        $this->assertSame('eins', $collection->one());
        $this->assertSame('zwei', $collection->two());
        $this->assertNull($collection->three());
    }

    public function testGetAttribute(): void
    {
        $collection = new Collection([
            'one' => 'eins',
            'two' => 'zwei',
        ]);

        $this->assertSame('eins', $collection->getAttribute($collection->toArray(), 'one'));
        $this->assertNull($collection->getAttribute($collection->toArray(), 'three'));

        $this->assertSame('zwei', $collection->getAttribute($collection, 'two'));
        $this->assertNull($collection->getAttribute($collection, 'three'));
    }

    public function testMagicGetCaseInsensitive(): void
    {
        $collection = new Collection(['Name' => 'Homer'], false);

        $this->assertSame('Homer', $collection->__get('name'));
        $this->assertNull($collection->__get('missing'));
    }

    public function testRandom(): void
    {
        $collection = new Collection(['a' => 1, 'b' => 2, 'c' => 3]);

        $this->assertCount(2, $collection->random(2));
        $this->assertCount(2, $collection->random(2, true));
    }
}
