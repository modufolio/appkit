<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Toolkit;

use Modufolio\Appkit\Toolkit\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Collection::class)]
class CollectionMutatorTest extends TestCase
{
    public function testData(): void
    {
        $collection = new Collection();

        $this->assertSame([], $collection->data());

        $collection->data([
            'three' => 'drei',
        ]);
        $this->assertSame([
            'three' => 'drei',
        ], $collection->data());

        $collection->data([
            'one' => 'eins',
            'two' => 'zwei',
        ]);
        $this->assertSame([
            'one' => 'eins',
            'two' => 'zwei',
        ], $collection->data());
    }

    public function testEmpty(): void
    {
        $collection = new Collection([
            'one' => 'eins',
            'two' => 'zwei',
        ]);

        $this->assertSame([
            'one' => 'eins',
            'two' => 'zwei',
        ], $collection->data());

        $this->assertSame([], $collection->empty()->data());
    }

    public function testSet(): void
    {
        $collection = new Collection();
        $this->assertNull($collection->one);
        $this->assertNull($collection->two);

        $collection->one = 'eins';
        $this->assertSame('eins', $collection->one);

        $collection->set('two', 'zwei');
        $this->assertSame('zwei', $collection->two);

        $collection->set([
            'three' => 'drei',
        ]);
        $this->assertSame('drei', $collection->three);
    }

    public function testAppend(): void
    {
        $collection = new Collection([
            'one' => 'eins',
        ]);

        $this->assertSame('eins', $collection->last());

        $collection->append('two', 'zwei');
        $this->assertSame('zwei', $collection->last());
    }

    public function testPrepend(): void
    {
        $collection = new Collection([
            'one' => 'eins',
        ]);

        $this->assertSame('eins', $collection->first());

        $collection->prepend('zero', 'null');
        $this->assertSame('null', $collection->zero());
    }

    public function testExtend(): void
    {
        $collection = new Collection([
            'one' => 'eins',
        ]);

        $result = $collection->extend([
            'two' => 'zwei',
        ]);

        $this->assertSame('eins', $result->one());
        $this->assertSame('zwei', $result->two());
    }

    public function testRemove(): void
    {
        $collection = new Collection([
            'one' => 'eins',
            'two' => 'zwei',
        ]);

        $this->assertSame('zwei', $collection->two());
        $collection->remove('two');
        $this->assertNull($collection->two());
    }

    public function testUnset(): void
    {
        $collection = new Collection([
            'one' => 'eins',
            'two' => 'zwei',
        ]);

        $this->assertSame('zwei', $collection->two());
        unset($collection->two);
        $this->assertNull($collection->two());
    }

    public function testMap()
    {
        $collection = new Collection([
            'one' => 'eins',
            'two' => 'zwei',
        ]);

        $this->assertSame('zwei', $collection->two());
        $collection->map(function ($item) {
            return $item.'-ish';
        });
        $this->assertSame('zwei-ish', $collection->two());
    }

    public function testPluck(): void
    {
        $collection = new Collection([
            [
                'username' => 'homer',
            ],
            [
                'username' => 'marge',
            ],
        ]);

        $this->assertSame(['homer', 'marge'], $collection->pluck('username'));
    }

    public function testPluckAndSplit(): void
    {
        $collection = new Collection([
            [
                'simpsons' => 'homer, marge',
            ],
            [
                'simpsons' => 'maggie, bart, lisa',
            ],
        ]);

        $expected = [
            'homer', 'marge', 'maggie', 'bart', 'lisa',
        ];

        $this->assertSame($expected, $collection->pluck('simpsons', ', '));
    }

    public function testPluckUnique(): void
    {
        $collection = new Collection([
            [
                'user' => 'homer',
            ],
            [
                'user' => 'homer',
            ],
            [
                'user' => 'marge',
            ],
        ]);

        $expected = ['homer', 'marge'];

        $this->assertSame($expected, $collection->pluck('user', null, true));
    }

    public function testWithout(): void
    {
        $collection = new Collection(['a' => 1, 'b' => 2, 'c' => 3]);

        $this->assertSame(['b' => 2], $collection->without('a', 'c')->toArray());
    }

    public function testAppendSingleValueAndNoArgs(): void
    {
        $collection = new Collection(['a']);

        $collection->append('b');
        $this->assertSame(['a', 'b'], $collection->toArray());

        $collection->append('key', 'c');
        $this->assertSame('c', $collection->get('key'));

        // no arguments is a no-op
        $collection->append();
        $this->assertCount(3, $collection);
    }

    public function testPrependSingleValueAndNoArgs(): void
    {
        $collection = new Collection(['b']);

        $collection->prepend('a');
        $this->assertSame(['a', 'b'], $collection->toArray());

        $collection->prepend('key', 'z');
        $this->assertSame('z', $collection->first());

        $collection->prepend();
        $this->assertCount(3, $collection);
    }
}
