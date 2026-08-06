<?php

namespace Modufolio\Appkit\Tests\Unit\Util;

use Doctrine\ORM\Mapping\Entity;
use Modufolio\Appkit\Util\ClassDetails;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassDetails::class)]
final class ClassDetailsTest extends TestCase
{
    public function testHasAttribute(): void
    {
        self::assertTrue((new ClassDetails(FixtureClassDetails::class))->hasAttribute(Entity::class));

        self::assertFalse((new ClassDetails(__CLASS__))->hasAttribute(Entity::class));
    }

    public function testGetFormFields(): void
    {
        $fields = (new ClassDetails(FixtureClassDetails::class))->getFormFields();

        self::assertSame(['name' => null, 'email' => null], $fields);
    }

    public function testGetPath(): void
    {
        self::assertSame(__FILE__, (new ClassDetails(FixtureClassDetails::class))->getPath());
    }
}

#[Entity]
final class FixtureClassDetails
{
    public ?int $id = null;
    public ?string $name = null;
    public ?string $email = null;
}
