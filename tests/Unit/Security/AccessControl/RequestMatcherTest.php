<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security\AccessControl;

use Modufolio\Appkit\Security\AccessControl\RequestMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestMatcher::class)]
class RequestMatcherTest extends TestCase
{
    public function testPrefixMatchesOnSegmentBoundaries(): void
    {
        $this->assertTrue(RequestMatcher::matches('/admin', '/admin'));
        $this->assertTrue(RequestMatcher::matches('/admin', '/admin/users'));
        $this->assertFalse(RequestMatcher::matches('/admin', '/administrator'));
    }

    public function testCatchAllPatternMatchesEverything(): void
    {
        $this->assertTrue(RequestMatcher::matches('/', '/'));
        $this->assertTrue(RequestMatcher::matches('/', '/anything/nested'));
    }

    public function testMissingLeadingSlashIsNormalized(): void
    {
        $this->assertTrue(RequestMatcher::matches('admin', '/admin/users'));
    }

    public function testSegmentPatternMatchesPosition(): void
    {
        $this->assertTrue(RequestMatcher::matches('api:0', '/api/users'));
        $this->assertTrue(RequestMatcher::matches('users:1', '/api/users/42'));
        $this->assertFalse(RequestMatcher::matches('api:1', '/api/users'));
        $this->assertFalse(RequestMatcher::matches('api:0', '/apix/users'));
    }
}
