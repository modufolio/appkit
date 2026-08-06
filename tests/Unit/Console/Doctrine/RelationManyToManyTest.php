<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Console\Doctrine;

use Doctrine\ORM\Mapping\ManyToManyInverseSideMapping;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Modufolio\Appkit\Console\Doctrine\RelationManyToMany;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

class FixtureM2MPost
{
}

class FixtureM2MTag
{
}

#[CoversClass(RelationManyToMany::class)]
class RelationManyToManyTest extends TestCase
{
    public function testTargetMethodNames(): void
    {
        $relation = new RelationManyToMany(
            propertyName: 'tags',
            targetClassName: 'App\\Entity\\Tag',
            targetPropertyName: 'blogPosts',
            isOwning: true,
        );

        $this->assertSame('addBlogPost', $relation->getTargetSetterMethodName());
        $this->assertSame('removeBlogPost', $relation->getTargetRemoverMethodName());
        $this->assertSame('addTag', $relation->getAdderMethodName());
        $this->assertSame('removeTag', $relation->getRemoverMethodName());
    }

    public function testCreateFromLegacyArrayMapping(): void
    {
        $relation = RelationManyToMany::createFromObject([
            'fieldName' => 'tags',
            'targetEntity' => 'App\\Entity\\Tag',
            'mappedBy' => null,
            'inversedBy' => 'posts',
            'isOwningSide' => true,
        ]);

        $this->assertSame('tags', $relation->getPropertyName());
        $this->assertSame('App\\Entity\\Tag', $relation->getTargetClassName());
        $this->assertNull($relation->getTargetPropertyName());
        $this->assertTrue($relation->isOwning());
        $this->assertTrue($relation->getMapInverseRelation());
    }

    public function testCreateFromOwningSideMapping(): void
    {
        $mapping = new ManyToManyOwningSideMapping('tags', FixtureM2MPost::class, FixtureM2MTag::class);
        $mapping->inversedBy = 'posts';

        $relation = RelationManyToMany::createFromObject($mapping);

        $this->assertSame('tags', $relation->getPropertyName());
        $this->assertSame(FixtureM2MTag::class, $relation->getTargetClassName());
        $this->assertSame('posts', $relation->getTargetPropertyName());
        $this->assertTrue($relation->isOwning());
        $this->assertTrue($relation->getMapInverseRelation());
    }

    public function testCreateFromOwningSideMappingWithoutInversedBy(): void
    {
        $mapping = new ManyToManyOwningSideMapping('tags', FixtureM2MPost::class, FixtureM2MTag::class);

        $relation = RelationManyToMany::createFromObject($mapping);

        $this->assertTrue($relation->isOwning());
        $this->assertFalse($relation->getMapInverseRelation());
    }

    public function testCreateFromInverseSideMapping(): void
    {
        $mapping = new ManyToManyInverseSideMapping('posts', FixtureM2MTag::class, FixtureM2MPost::class);
        $mapping->mappedBy = 'tags';

        $relation = RelationManyToMany::createFromObject($mapping);

        $this->assertSame('posts', $relation->getPropertyName());
        $this->assertSame(FixtureM2MPost::class, $relation->getTargetClassName());
        $this->assertSame('tags', $relation->getTargetPropertyName());
        $this->assertFalse($relation->isOwning());
        $this->assertTrue($relation->getMapInverseRelation());
    }
}
