<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Core;

use Modufolio\Appkit\Exception\UntrustedHostException;
use Modufolio\Appkit\Security\SecurityConfigurator;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Modufolio\Psr7\Http\ServerRequest;
use Modufolio\Psr7\Http\Uri;

/**
 * The trusted-hosts allowlist as enforced by the kernel: an untrusted Host
 * header is rejected before request state exists, so it can never reach the
 * base URL, template url() helpers, absolute route generation or the https
 * upgrade redirect.
 */
final class TrustedHostsKernelTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No firewall: every request goes straight to access control and
        // routing, which is all this test is about. Access control runs before
        // route matching, so the https upgrade fires for /secure without a
        // route behind it. (Public-access rules are skipped by the engine, so
        // the channel rule must be a plain one.)
        $security = new SecurityConfigurator();
        $security->accessControl('/secure', [], null, ['requires_channel' => 'https']);
        $this->app()->configureSecurity($security);
    }

    public function tearDown(): void
    {
        // The app is shared across test classes; never leak the allowlist.
        $this->app()->setRouterOptions(['trusted_hosts' => []]);
        $this->app()->accessControlRules = null;

        parent::tearDown();
    }

    public function testTrustedHostsIsAnAcceptedRouterOption(): void
    {
        $this->app()->setRouterOptions(['trusted_hosts' => ['example.com', '*.example.com']]);

        $this->assertSame(['example.com', '*.example.com'], $this->app()->trustedHosts()->toArray());
        $this->assertSame(['example.com', '*.example.com'], $this->app()->router()->trustedHosts()->toArray());
    }

    public function testUnknownRouterOptionsStillThrow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not support the following options: "nope"');

        $this->app()->setRouterOptions(['nope' => true]);
    }

    public function testMalformedTrustedHostsFailAtConfigurationTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->app()->setRouterOptions(['trusted_hosts' => ['https://example.com']]);
    }

    public function testNoAllowlistAcceptsAnyHost(): void
    {
        $this->get('http://anything.example/public')->assertOk();
    }

    public function testUntrustedHostIsRejectedBeforeRequestStateIsBuilt(): void
    {
        $this->app()->setRouterOptions(['trusted_hosts' => ['localhost']]);

        $this->get('http://localhost/public')->assertOk();
        $this->assertSame('http://localhost', $this->app()->baseUrl());

        $this->get('http://attacker.test/public')->assertStatus(400);

        // createState() threw before a state for the attacker request existed:
        // the kernel's base URL still belongs to the last trusted request.
        $this->assertSame('http://localhost', $this->app()->baseUrl());
        $this->assertStringNotContainsString('attacker.test', $this->app()->url('/reset-password'));
    }

    public function testWildcardCoversSubdomainsOnly(): void
    {
        $this->app()->setRouterOptions(['trusted_hosts' => ['*.example.com']]);

        $this->get('http://www.example.com/public')->assertOk();
        $this->get('http://example.com/public')->assertStatus(400);
    }

    public function testHostlessRequestsKeepWorking(): void
    {
        $this->app()->setRouterOptions(['trusted_hosts' => ['localhost']]);

        // The test client (like the console) issues relative URIs with no
        // host; those produce path-only URLs and are not a poisoning vector.
        $this->get('/public')->assertOk();
    }

    public function testHttpsUpgradeNeverRedirectsToAnUntrustedHost(): void
    {
        $this->app()->setRouterOptions(['trusted_hosts' => ['localhost']]);

        $this->get('http://localhost/secure')->assertRedirect('https://localhost/secure');

        $response = $this->get('http://attacker.test/secure');
        $response->assertStatus(400);
        $this->assertSame('', $response->getResponse()->getHeaderLine('Location'));
    }

    public function testHandleAuthenticationIsABackstopForHandBuiltState(): void
    {
        $this->app()->setRouterOptions(['trusted_hosts' => ['localhost']]);

        // An application that builds NativeApplicationState itself (bypassing
        // createState()) is still stopped at the first kernel entry point.
        $request = new ServerRequest('GET', new Uri('http://attacker.test/public'));

        $this->expectException(UntrustedHostException::class);
        $this->app()->handleAuthentication($request);
    }
}
