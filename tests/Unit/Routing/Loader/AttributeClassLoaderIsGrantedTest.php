<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Routing\Loader;

use Modufolio\Appkit\Attributes\IsGranted;
use Modufolio\Appkit\Routing\Loader\AttributeClassLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;

#[IsGranted('ROLE_ADMIN')]
class IsGrantedFixtureController
{
    public function inheritsClassOnly(): void
    {
    }

    #[IsGranted('ROLE_USER')]
    public function stacked(): void
    {
    }

    #[IsGranted(['ROLE_EDITOR', 'ROLE_VERIFIED'])]
    public function orWithinAttribute(): void
    {
    }

    #[IsGranted('')]
    public function emptyRole(): void
    {
    }
}

class ExposedAttributeClassLoader extends AttributeClassLoader
{
    public function build(string $method): Route
    {
        $route = new Route('/');
        $reflectionClass = new \ReflectionClass(IsGrantedFixtureController::class);
        $this->configureRoute($route, $reflectionClass, $reflectionClass->getMethod($method), new \stdClass());

        return $route;
    }
}

class AttributeClassLoaderIsGrantedTest extends TestCase
{
    private function rolesFor(string $method): mixed
    {
        return (new ExposedAttributeClassLoader())->build($method)->getDefault('_is_granted_roles');
    }

    public function testClassLevelOnlyProducesOneGroup(): void
    {
        $this->assertSame([['ROLE_ADMIN']], $this->rolesFor('inheritsClassOnly'));
    }

    public function testStackedAttributesAreSeparateGroups(): void
    {
        // Class ROLE_ADMIN + method ROLE_USER → two groups (AND between them).
        $this->assertSame([['ROLE_ADMIN'], ['ROLE_USER']], $this->rolesFor('stacked'));
    }

    public function testRolesWithinOneAttributeShareAGroup(): void
    {
        $this->assertSame([['ROLE_ADMIN'], ['ROLE_EDITOR', 'ROLE_VERIFIED']], $this->rolesFor('orWithinAttribute'));
    }

    public function testEmptyRolesAreDropped(): void
    {
        // The empty method-level role contributes no group; only the class group remains.
        $this->assertSame([['ROLE_ADMIN']], $this->rolesFor('emptyRole'));
    }
}
