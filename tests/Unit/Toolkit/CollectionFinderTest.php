<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Tests\Unit\Toolkit;

use Modufolio\Appkit\Toolkit\Collection;
use PHPUnit\Framework\TestCase;

class CollectionFinderTest extends TestCase
{
    public function testFindBy()
    {
        $collection = new Collection([
            [
                'name' => 'Bastian',
                'email' => 'bastian@getkirby.com'
            ],
            [
                'name' => 'Nico',
                'email' => 'nico@getkirby.com'
            ]
        ]);

        $this->assertSame([
            'name' => 'Bastian',
            'email' => 'bastian@getkirby.com'
        ], $collection->findBy('email', 'bastian@getkirby.com'));
    }

    public function testFindKey()
    {
        $collection = new Collection([
            'one' => 'eins',
            'two' => 'zwei'
        ]);

        $this->assertSame('zwei', $collection->find('two'));
    }
}
