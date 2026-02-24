<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Tests\Unit\Query;

use Modufolio\Appkit\Query\Arguments;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Modufolio\Appkit\Query\Arguments
 */
class ArgumentsTest extends TestCase
{
    /**
     * @covers ::factory
     */
    public function testFactory()
    {
        $arguments = Arguments::factory('1, 2, 3');
        $this->assertCount(3, $arguments);

        $arguments = Arguments::factory('1, 2, [3, 4]');
        $this->assertCount(3, $arguments);

        $arguments = Arguments::factory('1, 2, \'3, 4\'');
        $this->assertCount(3, $arguments);

        $arguments = Arguments::factory('1, 2, "3, 4"');
        $this->assertCount(3, $arguments);

        $arguments = Arguments::factory('1, 2, (3, 4)');
        $this->assertCount(3, $arguments);
    }

    public function testResolve()
    {
        $arguments = Arguments::factory('1, 2.3, 3');
        $this->assertSame([1, 2.3, 3], $arguments->resolve());

        $arguments = Arguments::factory('1, 2, [3.3, 4]');
        $this->assertSame([1, 2, [3.3, 4]], $arguments->resolve());

        $arguments = Arguments::factory('1, 2, \'3, 4\'');
        $this->assertSame([1, 2, '3, 4'], $arguments->resolve());

        $arguments = Arguments::factory('1, 2, "3, 4"');
        $this->assertSame([1, 2, '3, 4'], $arguments->resolve());

        $arguments = Arguments::factory('1, 2, \'(3, 4)\'');
        $this->assertSame([1, 2, '(3, 4)'], $arguments->resolve());
    }
}
