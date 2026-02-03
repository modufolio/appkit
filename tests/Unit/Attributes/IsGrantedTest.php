<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Attributes;

use Modufolio\Appkit\Attributes\IsGranted;
use PHPUnit\Framework\TestCase;

class IsGrantedTest extends TestCase
{
    public function testConstructorWithSingleRole(): void
    {
        $attribute = new IsGranted('ROLE_ADMIN');

        $this->assertSame(['ROLE_ADMIN'], $attribute->roles);
    }

    public function testConstructorWithMultipleRoles(): void
    {
        $roles = ['ROLE_ADMIN', 'ROLE_USER'];
        $attribute = new IsGranted($roles);

        $this->assertSame($roles, $attribute->roles);
    }

    public function testConstructorConvertsStringToArray(): void
    {
        $attribute = new IsGranted('ROLE_DEVELOPER');

        $this->assertIsArray($attribute->roles);
        $this->assertCount(1, $attribute->roles);
        $this->assertContains('ROLE_DEVELOPER', $attribute->roles);
    }

    public function testRolesIsPublic(): void
    {
        $attribute = new IsGranted(['ROLE_ADMIN']);

        $this->assertIsArray($attribute->roles);
        $this->assertContains('ROLE_ADMIN', $attribute->roles);
    }

    public function testIsAttributeClass(): void
    {
        $reflection = new \ReflectionClass(IsGranted::class);
        $attributes = $reflection->getAttributes(\Attribute::class);

        $this->assertNotEmpty($attributes);
    }

    public function testIsRepeatable(): void
    {
        $reflection = new \ReflectionClass(IsGranted::class);
        $attributes = $reflection->getAttributes();

        $this->assertCount(1, $attributes);
        $this->assertSame(\Attribute::class, $attributes[0]->getName());
    }

    public function testWithEmptyRole(): void
    {
        $attribute = new IsGranted('');

        $this->assertSame([''], $attribute->roles);
    }

    public function testWithMultipleRoleStrings(): void
    {
        $attribute = new IsGranted(['ROLE_ADMIN', 'ROLE_MODERATOR', 'ROLE_USER']);

        $this->assertCount(3, $attribute->roles);
        $this->assertContains('ROLE_ADMIN', $attribute->roles);
        $this->assertContains('ROLE_MODERATOR', $attribute->roles);
        $this->assertContains('ROLE_USER', $attribute->roles);
    }
}
