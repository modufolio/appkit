<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Toolkit;

use Modufolio\Appkit\Toolkit\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Collection::class)]
class CollectionFinderTest extends TestCase
{
    public function testFindBy()
    {
        $collection = new Collection([
            [
                'name' => 'Bastian',
                'email' => 'bastian@getkirby.com',
            ],
            [
                'name' => 'Nico',
                'email' => 'nico@getkirby.com',
            ],
        ]);

        $this->assertSame([
            'name' => 'Bastian',
            'email' => 'bastian@getkirby.com',
        ], $collection->findBy('email', 'bastian@getkirby.com'));
    }

    public function testFindKey()
    {
        $collection = new Collection([
            'one' => 'eins',
            'two' => 'zwei',
        ]);

        $this->assertSame('zwei', $collection->find('two'));
    }

    public function testFindMultipleKeys(): void
    {
        $collection = new Collection(['a' => 'A', 'b' => 'B', 'c' => 'C']);

        $found = $collection->find('a', 'c');

        $this->assertSame(['a' => 'A', 'c' => 'C'], $found->toArray());
    }

    public function testFindSingleKey(): void
    {
        $collection = new Collection(['a' => 'A']);

        $this->assertSame('A', $collection->find('a'));
    }

    public function testIntersection(): void
    {
        $a = new Collection(['a' => 1, 'b' => 2]);
        $b = new Collection(['b' => 20, 'c' => 30]);

        $this->assertSame(['b' => 20], $a->intersection($b)->toArray());
    }

    public function testIntersects(): void
    {
        $a = new Collection(['a' => 1, 'b' => 2]);
        $b = new Collection(['b' => 20]);
        $c = new Collection(['x' => 1]);

        $this->assertTrue($a->intersects($b));
        $this->assertFalse($a->intersects($c));
    }
}
