<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Core;

use Modufolio\Appkit\Core\ApplicationStateInterface;
use Modufolio\Appkit\Core\NativeApplicationState;
use Modufolio\Psr7\Http\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Symfony-style firewall restriction matching.
 *
 * A firewall handles a request only when EVERY restriction it declares
 * (pattern + methods + host + ips) matches; the first matching firewall
 * (in declaration order) wins. A restriction that is not declared imposes
 * no constraint.
 *
 * These tests exercise the public entry point getFirewallNameForRequest(),
 * which delegates to resolveFirewallNameForRequest() /
 * matchesFirewallRestrictions() on AbstractApplicationState.
 *
 * @see https://symfony.com/doc/current/security/firewall_restriction.html
 */
class FirewallRestrictionMatchingTest extends TestCase
{
    private function createApplicationState(array $firewallConfig): ApplicationStateInterface
    {
        $request = new ServerRequest('GET', '/test');

        return new NativeApplicationState($request, sys_get_temp_dir(), $firewallConfig);
    }

    // ================================================================
    // methods
    // ================================================================

    public function testMethodRestrictionScopesFirewallAndFallsThrough(): void
    {
        $state = $this->createApplicationState([
            'read_only' => ['pattern' => '/api', 'methods' => ['GET']],
            'main' => ['pattern' => '/api'],
        ]);

        // GET is allowed by the restricted firewall.
        $this->assertSame('read_only', $state->getFirewallNameForRequest(new ServerRequest('GET', '/api/x')));

        // POST is not in the methods list, so it falls through to the catch-all.
        $this->assertSame('main', $state->getFirewallNameForRequest(new ServerRequest('POST', '/api/x')));
    }

    public function testMethodRestrictionAcceptsMultipleMethodsAndIsCaseInsensitive(): void
    {
        $state = $this->createApplicationState([
            'read_only' => ['pattern' => '/api', 'methods' => ['GET', 'HEAD']],
            'main' => ['pattern' => '/api'],
        ]);

        $this->assertSame('read_only', $state->getFirewallNameForRequest(new ServerRequest('GET', '/api/x')));
        $this->assertSame('read_only', $state->getFirewallNameForRequest(new ServerRequest('HEAD', '/api/x')));

        // Lowercase method is normalised to uppercase before comparison.
        $this->assertSame('read_only', $state->getFirewallNameForRequest(new ServerRequest('get', '/api/x')));

        // Methods outside the list fall through.
        $this->assertSame('main', $state->getFirewallNameForRequest(new ServerRequest('POST', '/api/x')));
        $this->assertSame('main', $state->getFirewallNameForRequest(new ServerRequest('DELETE', '/api/x')));
    }

    // ================================================================
    // host
    // ================================================================

    public function testHostRestrictionMatchesOnlyDeclaredHost(): void
    {
        $state = $this->createApplicationState([
            'api' => ['pattern' => '/', 'host' => 'api.example.com'],
            'main' => ['pattern' => '/'],
        ]);

        $this->assertSame(
            'api',
            $state->getFirewallNameForRequest(new ServerRequest('GET', 'http://api.example.com/dashboard')),
        );

        // A different host falls through to the catch-all.
        $this->assertSame(
            'main',
            $state->getFirewallNameForRequest(new ServerRequest('GET', 'http://www.example.com/dashboard')),
        );
    }

    public function testHostRestrictionIsCaseInsensitive(): void
    {
        $state = $this->createApplicationState([
            'api' => ['pattern' => '/', 'host' => 'api.example.com'],
            'main' => ['pattern' => '/'],
        ]);

        // URI host casing must not defeat the match.
        $this->assertSame(
            'api',
            $state->getFirewallNameForRequest(new ServerRequest('GET', 'http://API.EXAMPLE.COM/dashboard')),
        );
    }

    // ================================================================
    // ips
    // ================================================================

    public function testIpRestrictionMatchesCidrRange(): void
    {
        $state = $this->createApplicationState([
            'internal' => ['pattern' => '/', 'ips' => ['10.0.0.0/8']],
            'public' => ['pattern' => '/'],
        ]);

        $inRange = new ServerRequest('GET', '/x', [], null, '1.1', ['REMOTE_ADDR' => '10.1.2.3']);
        $outOfRange = new ServerRequest('GET', '/x', [], null, '1.1', ['REMOTE_ADDR' => '192.168.1.1']);

        $this->assertSame('internal', $state->getFirewallNameForRequest($inRange));
        $this->assertSame('public', $state->getFirewallNameForRequest($outOfRange));
    }

    public function testIpRestrictionMatchesSingleIp(): void
    {
        $state = $this->createApplicationState([
            'trusted' => ['pattern' => '/', 'ips' => ['203.0.113.7']],
            'public' => ['pattern' => '/'],
        ]);

        $trusted = new ServerRequest('GET', '/x', [], null, '1.1', ['REMOTE_ADDR' => '203.0.113.7']);
        $other = new ServerRequest('GET', '/x', [], null, '1.1', ['REMOTE_ADDR' => '203.0.113.8']);

        $this->assertSame('trusted', $state->getFirewallNameForRequest($trusted));
        $this->assertSame('public', $state->getFirewallNameForRequest($other));
    }

    public function testIpRestrictionFallsThroughWhenRemoteAddrMissing(): void
    {
        $state = $this->createApplicationState([
            'internal' => ['pattern' => '/', 'ips' => ['10.0.0.0/8']],
            'public' => ['pattern' => '/'],
        ]);

        // No REMOTE_ADDR server param => IP restriction cannot match.
        $this->assertSame('public', $state->getFirewallNameForRequest(new ServerRequest('GET', '/x')));
    }

    // ================================================================
    // combined restrictions
    // ================================================================

    public function testAllRestrictionsMustMatchTogether(): void
    {
        $state = $this->createApplicationState([
            'secure_api' => [
                'pattern' => '/api',
                'methods' => ['POST'],
                'host' => 'api.example.com',
                'ips' => ['10.0.0.0/8'],
            ],
            'main' => ['pattern' => '/'],
        ]);

        // Everything matches => restricted firewall wins.
        $allMatch = new ServerRequest('POST', 'http://api.example.com/api/x', [], null, '1.1', ['REMOTE_ADDR' => '10.1.2.3']);
        $this->assertSame('secure_api', $state->getFirewallNameForRequest($allMatch));

        // pattern + method + host match, but IP is out of range => falls through.
        $wrongIp = new ServerRequest('POST', 'http://api.example.com/api/x', [], null, '1.1', ['REMOTE_ADDR' => '192.168.1.1']);
        $this->assertSame('main', $state->getFirewallNameForRequest($wrongIp));

        // Wrong method with everything else correct => falls through.
        $wrongMethod = new ServerRequest('GET', 'http://api.example.com/api/x', [], null, '1.1', ['REMOTE_ADDR' => '10.1.2.3']);
        $this->assertSame('main', $state->getFirewallNameForRequest($wrongMethod));

        // Wrong host with everything else correct => falls through.
        $wrongHost = new ServerRequest('POST', 'http://www.example.com/api/x', [], null, '1.1', ['REMOTE_ADDR' => '10.1.2.3']);
        $this->assertSame('main', $state->getFirewallNameForRequest($wrongHost));
    }

    // ================================================================
    // no restrictions (regression)
    // ================================================================

    public function testFirewallWithOnlyPatternMatchesAnyRequest(): void
    {
        $state = $this->createApplicationState([
            'main' => ['pattern' => '/api'],
        ]);

        $this->assertSame(
            'main',
            $state->getFirewallNameForRequest(new ServerRequest('GET', 'http://api.example.com/api/x', [], null, '1.1', ['REMOTE_ADDR' => '10.1.2.3'])),
        );
        $this->assertSame(
            'main',
            $state->getFirewallNameForRequest(new ServerRequest('POST', 'http://www.other.com/api/x', [], null, '1.1', ['REMOTE_ADDR' => '203.0.113.1'])),
        );
        $this->assertSame(
            'main',
            $state->getFirewallNameForRequest(new ServerRequest('DELETE', '/api/x')),
        );
    }
}
