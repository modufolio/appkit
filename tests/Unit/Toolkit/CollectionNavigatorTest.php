<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Toolkit;

use Modufolio\Appkit\Toolkit\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Collection::class)]
class CollectionNavigatorTest extends TestCase
{
    public function testFirstLast()
    {
        $collection = new Collection([
            'one' => 'eins',
            'two' => 'zwei',
            'three' => 'drei',
            'four' => 'vier',
        ]);

        $this->assertSame('eins', $collection->first());
        $this->assertSame('vier', $collection->last());
    }

    public function testNth()
    {
        $collection = new Collection([
            'one' => 'eins',
            'two' => 'zwei',
            'three' => 'drei',
            'four' => 'vier',
        ]);

        $this->assertSame('eins', $collection->nth(0));
        $this->assertSame('zwei', $collection->nth(1));
        $this->assertSame('drei', $collection->nth(2));
        $this->assertSame('vier', $collection->nth(3));
    }

    public function testEmptinessCheckers(): void
    {
        $empty = new Collection([]);
        $two = new Collection([1, 2]);
        $three = new Collection([1, 2, 3]);

        $this->assertTrue($empty->isEmpty());
        $this->assertFalse($two->isEmpty());
        $this->assertTrue($two->isNotEmpty());
        $this->assertTrue($two->isEven());
        $this->assertFalse($three->isEven());
        $this->assertTrue($three->isOdd());
        $this->assertFalse($two->isOdd());
    }
}
