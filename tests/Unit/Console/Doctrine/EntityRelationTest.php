<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Console\Doctrine;

use Modufolio\Appkit\Console\Doctrine\EntityRelation;
use Modufolio\Appkit\Console\Doctrine\RelationManyToMany;
use Modufolio\Appkit\Console\Doctrine\RelationManyToOne;
use Modufolio\Appkit\Console\Doctrine\RelationOneToMany;
use Modufolio\Appkit\Console\Doctrine\RelationOneToOne;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityRelation::class)]
class EntityRelationTest extends TestCase
{
    public function testGetValidRelationTypes(): void
    {
        $this->assertSame(
            ['ManyToOne', 'OneToMany', 'ManyToMany', 'OneToOne'],
            EntityRelation::getValidRelationTypes()
        );
    }

    public function testConstructorRejectsInvalidType(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid relation type "FooToBar"');
        new EntityRelation('FooToBar', 'App\\Entity\\Post', 'App\\Entity\\Tag');
    }

    public function testConstructorRejectsOneToMany(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Use ManyToOne instead of OneToMany');
        new EntityRelation(EntityRelation::ONE_TO_MANY, 'App\\Entity\\Post', 'App\\Entity\\Tag');
    }

    public function testBasicGetters(): void
    {
        $relation = new EntityRelation(
            EntityRelation::MANY_TO_ONE,
            'App\\Entity\\Comment',
            'App\\Entity\\Post'
        );
        $relation->setOwningProperty('post');
        $relation->setInverseProperty('comments');
        $relation->setIsNullable(true);
        $relation->setOrphanRemoval(true);

        $this->assertSame(EntityRelation::MANY_TO_ONE, $relation->getType());
        $this->assertSame('App\\Entity\\Comment', $relation->getOwningClass());
        $this->assertSame('App\\Entity\\Post', $relation->getInverseClass());
        $this->assertSame('post', $relation->getOwningProperty());
        $this->assertSame('comments', $relation->getInverseProperty());
        $this->assertTrue($relation->isNullable());
        $this->assertFalse($relation->isSelfReferencing());
        $this->assertTrue($relation->getMapInverseRelation());
    }

    public function testSelfReferencing(): void
    {
        $relation = new EntityRelation(
            EntityRelation::MANY_TO_ONE,
            'App\\Entity\\Category',
            'App\\Entity\\Category'
        );

        $this->assertTrue($relation->isSelfReferencing());
    }

    public function testSetInversePropertyThrowsWhenInverseRelationNotMapped(): void
    {
        $relation = new EntityRelation(
            EntityRelation::MANY_TO_ONE,
            'App\\Entity\\Comment',
            'App\\Entity\\Post'
        );
        $relation->setMapInverseRelation(false);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot call setInverseProperty()');
        $relation->setInverseProperty('comments');
    }

    public function testSetMapInverseRelationThrowsWhenInversePropertyAlreadySet(): void
    {
        $relation = new EntityRelation(
            EntityRelation::MANY_TO_ONE,
            'App\\Entity\\Comment',
            'App\\Entity\\Post'
        );
        $relation->setInverseProperty('comments');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot set setMapInverseRelation() to true');
        $relation->setMapInverseRelation(true);
    }

    public function testManyToOneRelations(): void
    {
        $relation = new EntityRelation(
            EntityRelation::MANY_TO_ONE,
            'App\\Entity\\Comment',
            'App\\Entity\\Post'
        );
        $relation->setOwningProperty('post');
        $relation->setInverseProperty('comments');
        $relation->setIsNullable(true);
        $relation->setOrphanRemoval(true);

        $owning = $relation->getOwningRelation();
        $this->assertInstanceOf(RelationManyToOne::class, $owning);
        $this->assertSame('post', $owning->getPropertyName());
        $this->assertSame('App\\Entity\\Post', $owning->getTargetClassName());
        $this->assertSame('comments', $owning->getTargetPropertyName());
        $this->assertTrue($owning->isOwning());
        $this->assertTrue($owning->isNullable());

        $inverse = $relation->getInverseRelation();
        $this->assertInstanceOf(RelationOneToMany::class, $inverse);
        $this->assertSame('comments', $inverse->getPropertyName());
        $this->assertSame('App\\Entity\\Comment', $inverse->getTargetClassName());
        $this->assertSame('post', $inverse->getTargetPropertyName());
        $this->assertFalse($inverse->isOwning());
        $this->assertTrue($inverse->getOrphanRemoval());
    }

    public function testManyToManyRelations(): void
    {
        $relation = new EntityRelation(
            EntityRelation::MANY_TO_MANY,
            'App\\Entity\\Post',
            'App\\Entity\\Tag'
        );
        $relation->setOwningProperty('tags');
        $relation->setInverseProperty('posts');

        $owning = $relation->getOwningRelation();
        $this->assertInstanceOf(RelationManyToMany::class, $owning);
        $this->assertSame('tags', $owning->getPropertyName());
        $this->assertSame('App\\Entity\\Tag', $owning->getTargetClassName());
        $this->assertTrue($owning->isOwning());

        $inverse = $relation->getInverseRelation();
        $this->assertInstanceOf(RelationManyToMany::class, $inverse);
        $this->assertSame('posts', $inverse->getPropertyName());
        $this->assertSame('App\\Entity\\Post', $inverse->getTargetClassName());
        $this->assertFalse($inverse->isOwning());
    }

    public function testOneToOneRelations(): void
    {
        $relation = new EntityRelation(
            EntityRelation::ONE_TO_ONE,
            'App\\Entity\\User',
            'App\\Entity\\Profile'
        );
        $relation->setOwningProperty('profile');
        $relation->setInverseProperty('user');
        $relation->setIsNullable(true);

        $owning = $relation->getOwningRelation();
        $this->assertInstanceOf(RelationOneToOne::class, $owning);
        $this->assertSame('profile', $owning->getPropertyName());
        $this->assertSame('App\\Entity\\Profile', $owning->getTargetClassName());
        $this->assertTrue($owning->isOwning());
        $this->assertTrue($owning->isNullable());

        $inverse = $relation->getInverseRelation();
        $this->assertInstanceOf(RelationOneToOne::class, $inverse);
        $this->assertSame('user', $inverse->getPropertyName());
        $this->assertSame('App\\Entity\\User', $inverse->getTargetClassName());
        $this->assertFalse($inverse->isOwning());
        $this->assertTrue($inverse->isNullable());
    }
}
