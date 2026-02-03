<?php

namespace Modufolio\Appkit\Tests\Unit\Util;

use Modufolio\Appkit\Util\ClassDetails;
use Doctrine\ORM\Mapping\Entity;
use PHPUnit\Framework\TestCase;

final class ClassDetailsTest extends TestCase
{
    public function testHasAttribute(): void
    {
        self::assertTrue((new ClassDetails(FixtureClassDetails::class))->hasAttribute(Entity::class));

        self::assertFalse((new ClassDetails(__CLASS__))->hasAttribute(Entity::class));
    }
}

#[Entity]
final class FixtureClassDetails
{
}
