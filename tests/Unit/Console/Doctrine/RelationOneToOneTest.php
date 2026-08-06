<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Console\Doctrine;

use Doctrine\ORM\Mapping\JoinColumnMapping;
use Doctrine\ORM\Mapping\OneToOneInverseSideMapping;
use Doctrine\ORM\Mapping\OneToOneOwningSideMapping;
use Modufolio\Appkit\Console\Doctrine\RelationOneToOne;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

class FixtureO2OUser
{
}

class FixtureO2OProfile
{
}

#[CoversClass(RelationOneToOne::class)]
class RelationOneToOneTest extends TestCase
{
    public function testTargetMethodNames(): void
    {
        $relation = new RelationOneToOne(
            propertyName: 'profile',
            targetClassName: 'App\\Entity\\Profile',
            targetPropertyName: 'user',
            isOwning: true,
        );

        $this->assertSame('getUser', $relation->getTargetGetterMethodName());
        $this->assertSame('setUser', $relation->getTargetSetterMethodName());
    }

    public function testCreateFromOwningSideMappingWithJoinColumn(): void
    {
        $joinColumn = new JoinColumnMapping('profile_id', 'id');
        $joinColumn->nullable = false;

        $mapping = new OneToOneOwningSideMapping('profile', FixtureO2OUser::class, FixtureO2OProfile::class);
        $mapping->inversedBy = 'user';
        $mapping->joinColumns = [$joinColumn];

        $relation = RelationOneToOne::createFromObject($mapping);

        $this->assertSame('profile', $relation->getPropertyName());
        $this->assertSame(FixtureO2OProfile::class, $relation->getTargetClassName());
        $this->assertSame('user', $relation->getTargetPropertyName());
        $this->assertTrue($relation->isOwning());
        $this->assertTrue($relation->getMapInverseRelation());
        $this->assertFalse($relation->isNullable());
    }

    public function testCreateFromOwningSideMappingWithoutJoinColumnsDefaultsToNullable(): void
    {
        $mapping = new OneToOneOwningSideMapping('profile', FixtureO2OUser::class, FixtureO2OProfile::class);

        $relation = RelationOneToOne::createFromObject($mapping);

        $this->assertTrue($relation->isOwning());
        $this->assertFalse($relation->getMapInverseRelation());
        $this->assertTrue($relation->isNullable());
    }

    public function testCreateFromInverseSideMapping(): void
    {
        $mapping = new OneToOneInverseSideMapping('user', FixtureO2OProfile::class, FixtureO2OUser::class);
        $mapping->mappedBy = 'profile';

        $relation = RelationOneToOne::createFromObject($mapping);

        $this->assertSame('user', $relation->getPropertyName());
        $this->assertSame(FixtureO2OUser::class, $relation->getTargetClassName());
        $this->assertSame('profile', $relation->getTargetPropertyName());
        $this->assertFalse($relation->isOwning());
        $this->assertTrue($relation->getMapInverseRelation());
        $this->assertTrue($relation->isNullable());
    }
}
