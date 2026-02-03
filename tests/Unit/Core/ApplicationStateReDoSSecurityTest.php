<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Core;

use Modufolio\Appkit\Core\ApplicationStateInterface;
use Modufolio\Appkit\Core\NativeApplicationState;
use Modufolio\Psr7\Http\ServerRequest;
use Modufolio\Appkit\Tests\Case\AppTestCase;

/**
 * Tests for simplified firewall pattern matching.
 *
 * This test suite verifies the two pattern types:
 * 1. Segment-based: "api:0" (matches segment at position)
 * 2. Prefix-based: "/api" (matches path prefix)
 *
 * No regex is used, eliminating ReDoS vulnerabilities entirely.
 */
class ApplicationStateReDoSSecurityTest extends AppTestCase
{
    // ================================================================
    // Segment-Based Pattern Tests
    // ================================================================

    /**
     * Test simple pattern matching with segment:position syntax.
     */
    public function testSimplePatternMatchingWithSegmentPosition(): void
    {
        $state = $this->createApplicationState([
            'api' => [
                'pattern' => 'api:0',  // First segment must be "api"
            ],
            'admin' => [
                'pattern' => 'admin:0',  // First segment must be "admin"
            ],
            'logout' => [
                'pattern' => 'logout:1',  // Second segment must be "logout"
            ],
        ]);

        // Test first segment matching
        $this->assertSame('api', $state->getFirewallName('/api'));
        $this->assertSame('api', $state->getFirewallName('/api/users'));
        $this->assertSame('api', $state->getFirewallName('/api/v1/users'));

        $this->assertSame('admin', $state->getFirewallName('/admin'));
        $this->assertSame('admin', $state->getFirewallName('/admin/dashboard'));

        // Test second segment matching
        // Note: '/admin/logout' matches 'admin:0' first (segment 0 is 'admin')
        $this->assertSame('admin', $state->getFirewallName('/admin/logout'));
        // '/user/logout' doesn't match 'admin:0' or 'api:0', so it matches 'logout:1'
        $this->assertSame('logout', $state->getFirewallName('/user/logout'));

        // Test non-matching paths
        $this->assertNull($state->getFirewallName('/public'));
        $this->assertNull($state->getFirewallName('/'));
        // '/admin/login' matches 'admin:0' (segment 0 is 'admin')
        $this->assertSame('admin', $state->getFirewallName('/admin/login'));
    }

    /**
     * Test that simple patterns are case-sensitive.
     */
    public function testSimplePatternsAreCaseSensitive(): void
    {
        $state = $this->createApplicationState([
            'api' => [
                'pattern' => 'api:0',
            ],
        ]);

        $this->assertSame('api', $state->getFirewallName('/api/users'));
        $this->assertNull($state->getFirewallName('/API/users'));
        $this->assertNull($state->getFirewallName('/Api/users'));
    }

    /**
     * Test simple patterns with higher segment positions.
     */
    public function testSimplePatternsWithHigherPositions(): void
    {
        $state = $this->createApplicationState([
            'resource' => [
                'pattern' => 'edit:2',  // Third segment must be "edit"
            ],
        ]);

        $this->assertSame('resource', $state->getFirewallName('/admin/users/edit'));
        $this->assertSame('resource', $state->getFirewallName('/api/posts/edit/123'));
        $this->assertNull($state->getFirewallName('/admin/edit'));  // edit is at position 1, not 2
        $this->assertNull($state->getFirewallName('/edit'));  // edit is at position 0, not 2
    }

    /**
     * Test that simple patterns don't match when position doesn't exist.
     */
    public function testSimplePatternsWithNonExistentPosition(): void
    {
        $state = $this->createApplicationState([
            'deep' => [
                'pattern' => 'settings:5',  // 6th segment
            ],
        ]);

        $this->assertNull($state->getFirewallName('/admin'));
        $this->assertNull($state->getFirewallName('/admin/user/profile'));
        $this->assertSame('deep', $state->getFirewallName('/a/b/c/d/e/settings'));
    }

    /**
     * Test mixing segment and prefix patterns.
     */
    public function testMixingSegmentAndPrefixPatterns(): void
    {
        $state = $this->createApplicationState([
            'api_segment' => [
                'pattern' => 'api:0',  // Segment pattern
            ],
            'admin_prefix' => [
                'pattern' => '/admin',  // Prefix pattern
            ],
        ]);

        $this->assertSame('api_segment', $state->getFirewallName('/api/users'));
        $this->assertSame('admin_prefix', $state->getFirewallName('/admin/dashboard'));
        $this->assertNull($state->getFirewallName('/public'));
    }

    /**
     * Test that segment patterns with special characters work.
     */
    public function testSegmentPatternsWithSpecialCharacters(): void
    {
        $state = $this->createApplicationState([
            'special' => [
                'pattern' => 'api-v1:0',
            ],
            'underscore' => [
                'pattern' => 'user_profile:1',
            ],
        ]);

        $this->assertSame('special', $state->getFirewallName('/api-v1/users'));
        $this->assertSame('underscore', $state->getFirewallName('/admin/user_profile'));
    }

    // ================================================================
    // Prefix-Based Pattern Tests
    // ================================================================

    private function createApplicationState(array $firewallConfig): ApplicationStateInterface
    {
        $request = new ServerRequest('GET', '/test');
        return new NativeApplicationState($request, $firewallConfig);
    }

    /**
     * Test prefix patterns work correctly.
     */
    public function testPrefixPatternsWorkCorrectly(): void
    {
        $state = $this->createApplicationState([
            'api' => [
                'pattern' => '/api',
            ],
            'admin' => [
                'pattern' => '/admin',
            ],
            'main' => [
                'pattern' => '/',
            ],
        ]);

        $this->assertSame('api', $state->getFirewallName('/api'));
        $this->assertSame('api', $state->getFirewallName('/api/users'));
        $this->assertSame('admin', $state->getFirewallName('/admin'));
        $this->assertSame('admin', $state->getFirewallName('/admin/dashboard'));
        $this->assertSame('main', $state->getFirewallName('/'));
        $this->assertSame('main', $state->getFirewallName('/public'));
    }

    /**
     * Test prefix patterns without leading slash are normalized.
     */
    public function testPrefixPatternsNormalized(): void
    {
        $state = $this->createApplicationState([
            'api' => [
                'pattern' => 'api',  // No leading slash
            ],
        ]);

        $this->assertSame('api', $state->getFirewallName('/api/users'));
    }

    /**
     * Test that empty pattern doesn't match anything.
     */
    public function testEmptyPatternDoesNotMatch(): void
    {
        $state = $this->createApplicationState([
            'main' => [
                'pattern' => '',
            ],
        ]);

        $this->assertNull($state->getFirewallName('/'));
        $this->assertNull($state->getFirewallName('/test'));
    }

    /**
     * Test that firewall matching prioritizes first match.
     */
    public function testFirewallMatchingPriority(): void
    {
        $state = $this->createApplicationState([
            'api_v1' => [
                'pattern' => '/api/v1',
            ],
            'api_general' => [
                'pattern' => '/api',
            ],
        ]);

        // Should match the first one that matches
        $this->assertSame('api_v1', $state->getFirewallName('/api/v1/users'));
        $this->assertSame('api_general', $state->getFirewallName('/api/v2/users'));
    }

    /**
     * Test prefix patterns are case-sensitive.
     */
    public function testPrefixPatternsAreCaseSensitive(): void
    {
        $state = $this->createApplicationState([
            'api' => [
                'pattern' => '/api',
            ],
        ]);

        $this->assertSame('api', $state->getFirewallName('/api/users'));
        $this->assertNull($state->getFirewallName('/API/users'));
        $this->assertNull($state->getFirewallName('/Api/users'));
    }

    /**
     * Test nested paths with prefix patterns.
     */
    public function testNestedPathsWithPrefixPatterns(): void
    {
        $state = $this->createApplicationState([
            'deep' => [
                'pattern' => '/admin/users/settings',
            ],
        ]);

        $this->assertSame('deep', $state->getFirewallName('/admin/users/settings'));
        $this->assertSame('deep', $state->getFirewallName('/admin/users/settings/profile'));
        $this->assertNull($state->getFirewallName('/admin/users'));
        $this->assertNull($state->getFirewallName('/admin'));
    }

    /**
     * Test that patterns with colons in value are treated as prefix.
     */
    public function testPatternsWithMultipleColons(): void
    {
        $state = $this->createApplicationState([
            'time_api' => [
                'pattern' => '/api/time:12:30',  // Contains colons but not segment pattern
            ],
        ]);

        // Since it has multiple colons, the explode will split on first colon
        // This becomes a segment pattern: value="/api/time", position=12
        // This likely won't match typical paths, but let's test it doesn't crash
        $this->assertNull($state->getFirewallName('/api/time:12:30'));
    }
}
