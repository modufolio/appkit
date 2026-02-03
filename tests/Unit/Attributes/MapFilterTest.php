<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Attributes;

use Modufolio\Appkit\Attributes\MapFilter;
use PHPUnit\Framework\TestCase;

class MapFilterTest extends TestCase
{
    public function testConstructorWithDefaultName(): void
    {
        $attribute = new MapFilter();

        $this->assertNull($attribute->name);
    }

    public function testConstructorWithName(): void
    {
        $attribute = new MapFilter('status');

        $this->assertSame('status', $attribute->name);
    }

    public function testNameIsPublic(): void
    {
        $attribute = new MapFilter('search');

        $this->assertSame('search', $attribute->name);
    }

    public function testIsAttributeClass(): void
    {
        $reflection = new \ReflectionClass(MapFilter::class);
        $attributes = $reflection->getAttributes(\Attribute::class);

        $this->assertNotEmpty($attributes);
    }

    public function testTargetsParameter(): void
    {
        $reflection = new \ReflectionClass(MapFilter::class);
        $attributes = $reflection->getAttributes();

        $this->assertCount(1, $attributes);
        $this->assertSame(\Attribute::class, $attributes[0]->getName());
    }

    public function testWithEmptyString(): void
    {
        $attribute = new MapFilter('');

        $this->assertSame('', $attribute->name);
    }

    public function testWithComplexFilterName(): void
    {
        $attribute = new MapFilter('user.email.domain');

        $this->assertSame('user.email.domain', $attribute->name);
    }

    public function testNullNameExplicitly(): void
    {
        $attribute = new MapFilter(null);

        $this->assertNull($attribute->name);
    }
}
