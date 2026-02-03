<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Attributes;

use Modufolio\Appkit\Attributes\FindEntity;
use PHPUnit\Framework\TestCase;

class FindEntityTest extends TestCase
{
    public function testConstructorWithDefaultCriteria(): void
    {
        $attribute = new FindEntity();

        $this->assertSame([], $attribute->criteria);
    }

    public function testConstructorWithCriteria(): void
    {
        $criteria = ['id' => 123, 'status' => 'active'];
        $attribute = new FindEntity($criteria);

        $this->assertSame($criteria, $attribute->criteria);
    }

    public function testCriteriaIsPublic(): void
    {
        $attribute = new FindEntity(['email' => 'test@example.com']);

        $this->assertArrayHasKey('email', $attribute->criteria);
        $this->assertSame('test@example.com', $attribute->criteria['email']);
    }

    public function testIsAttributeClass(): void
    {
        $reflection = new \ReflectionClass(FindEntity::class);
        $attributes = $reflection->getAttributes(\Attribute::class);

        $this->assertNotEmpty($attributes);
    }

    public function testTargetsParameter(): void
    {
        $reflection = new \ReflectionClass(FindEntity::class);
        $attributes = $reflection->getAttributes();

        $this->assertCount(1, $attributes);
        $this->assertSame(\Attribute::class, $attributes[0]->getName());
    }

    public function testWithComplexCriteria(): void
    {
        $criteria = [
            'user.id' => 456,
            'status' => ['active', 'pending'],
            'created' => ['>=', '2024-01-01']
        ];

        $attribute = new FindEntity($criteria);

        $this->assertSame($criteria, $attribute->criteria);
    }

    public function testEmptyCriteria(): void
    {
        $attribute = new FindEntity([]);

        $this->assertEmpty($attribute->criteria);
        $this->assertIsArray($attribute->criteria);
    }
}
