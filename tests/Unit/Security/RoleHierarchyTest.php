<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security;

use Modufolio\Appkit\Security\RoleHierarchy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoleHierarchy::class)]
class RoleHierarchyTest extends TestCase
{
    private function hierarchy(): RoleHierarchy
    {
        return new RoleHierarchy([
            'ROLE_SUPER_ADMIN' => ['ROLE_ADMIN'],
            'ROLE_ADMIN' => ['ROLE_USER'],
            'ROLE_USER' => ['ROLE_GUEST'],
        ]);
    }

    public function testDirectRoleIsAlwaysReachable(): void
    {
        $this->assertSame(['ROLE_GUEST'], $this->hierarchy()->getReachableRoles(['ROLE_GUEST']));
    }

    public function testSingleLevelInheritance(): void
    {
        $this->assertSame(
            ['ROLE_USER', 'ROLE_GUEST'],
            $this->hierarchy()->getReachableRoles(['ROLE_USER'])
        );
    }

    public function testTransitiveInheritance(): void
    {
        $this->assertSame(
            ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN', 'ROLE_USER', 'ROLE_GUEST'],
            $this->hierarchy()->getReachableRoles(['ROLE_SUPER_ADMIN'])
        );
    }

    public function testMultipleInputRolesAreMergedWithoutDuplicates(): void
    {
        $this->assertSame(
            ['ROLE_ADMIN', 'ROLE_USER', 'ROLE_GUEST'],
            $this->hierarchy()->getReachableRoles(['ROLE_ADMIN', 'ROLE_USER'])
        );
    }

    public function testUnknownRoleIsPassedThrough(): void
    {
        $this->assertSame(
            ['ROLE_CUSTOM'],
            $this->hierarchy()->getReachableRoles(['ROLE_CUSTOM'])
        );
    }

    public function testEmptyInput(): void
    {
        $this->assertSame([], $this->hierarchy()->getReachableRoles([]));
    }

    public function testCyclicHierarchyDoesNotLoopForever(): void
    {
        $hierarchy = new RoleHierarchy([
            'ROLE_A' => ['ROLE_B'],
            'ROLE_B' => ['ROLE_C'],
            'ROLE_C' => ['ROLE_A'],
        ]);

        $this->assertSame(
            ['ROLE_A', 'ROLE_B', 'ROLE_C'],
            $hierarchy->getReachableRoles(['ROLE_A'])
        );
    }

    public function testSelfReferencingRole(): void
    {
        $hierarchy = new RoleHierarchy(['ROLE_A' => ['ROLE_A', 'ROLE_B']]);

        $this->assertSame(['ROLE_A', 'ROLE_B'], $hierarchy->getReachableRoles(['ROLE_A']));
    }

    public function testRepeatedCallsReturnTheSameResult(): void
    {
        $hierarchy = $this->hierarchy();

        $first = $hierarchy->getReachableRoles(['ROLE_ADMIN']);
        $second = $hierarchy->getReachableRoles(['ROLE_ADMIN']);

        $this->assertSame($first, $second);
    }

    public function testCacheKeyIsCollisionFree(): void
    {
        // ['ROLE_A,B'] and ['ROLE_A', 'B'] would collide under a naive
        // implode(',') cache key; the results must stay distinct.
        $hierarchy = new RoleHierarchy(['ROLE_A' => ['ROLE_X']]);

        $this->assertSame(['ROLE_A,B'], $hierarchy->getReachableRoles(['ROLE_A,B']));
        $this->assertSame(['ROLE_A', 'B', 'ROLE_X'], $hierarchy->getReachableRoles(['ROLE_A', 'B']));
    }

    public function testCacheEvictionKeepsAnswersCorrect(): void
    {
        // Push well past MAX_CACHE_ENTRIES (256) distinct inputs, then verify
        // an early entry still resolves correctly after eviction.
        $hierarchy = $this->hierarchy();
        $expected = $hierarchy->getReachableRoles(['ROLE_SUPER_ADMIN']);

        for ($i = 0; $i < 300; ++$i) {
            $hierarchy->getReachableRoles(['ROLE_DYNAMIC_'.$i]);
        }

        $this->assertSame($expected, $hierarchy->getReachableRoles(['ROLE_SUPER_ADMIN']));
    }
}
