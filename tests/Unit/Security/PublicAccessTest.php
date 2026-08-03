<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security;

use Modufolio\Appkit\Security\SecurityConfigurator;
use Modufolio\Appkit\Tests\Case\AppTestCase;

/**
 * PUBLIC_ACCESS: a path declared public with publicPath() is served anonymously
 * inside its firewall — only the entry-point redirect is waived. The rule is
 * skipped by enforceAccessControl(), so it neither grants nor restricts
 * anything; other rules still apply.
 */
class PublicAccessTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshDatabase();
        $this->loadFixtures();
    }

    private function configureSecurity(?array $publicMethods = null): void
    {
        $security = new SecurityConfigurator();
        $security->firewall('main', [
            'pattern' => '/',
            'authenticators' => ['form_login'],
            'entry_point' => '/login',
            'logout' => ['path' => '/logout', 'target' => '/login'],
        ]);
        $security->publicPath('/public', $publicMethods);
        $security->accessControl('/profile', ['ROLE_ADMIN']);

        $this->app()->configureSecurity($security);
    }

    public function testPublicPathIsReachableAnonymouslyInsideTheFirewall(): void
    {
        $this->configureSecurity();

        // The same firewall still bounces everything else to the entry point.
        $this->get('/')->assertRedirect('/login');
        $this->get('/public')->assertStatus(200);
    }

    public function testPublicPathShorthandProducesAPublicAccessRule(): void
    {
        $security = new SecurityConfigurator();
        $security->publicPath('/public', ['GET']);

        $this->assertSame([
            ['path' => '/public', 'roles' => [SecurityConfigurator::PUBLIC_ACCESS], 'methods' => ['GET']],
        ], $security->getAccessControlRules());
    }

    public function testMethodRestrictedExemptionOnlyCoversThoseMethods(): void
    {
        $this->configureSecurity(['GET']);

        // Readable anonymously ...
        $this->get('/public')->assertStatus(200);

        // ... but writing still requires a login.
        $this->post('/public', ['x' => 'y'])->assertRedirect('/login');
    }

    public function testPublicAccessRuleDoesNotRestrictAnAuthenticatedUser(): void
    {
        $this->configureSecurity();
        $this->login();

        // The PUBLIC_ACCESS rule is skipped by enforceAccessControl(): it must
        // not be treated as a required role the user does not have.
        $this->get('/public')->assertStatus(200);
    }

    public function testFirewallScopedPublicPathDoesNotLeakIntoOtherFirewalls(): void
    {
        $security = new SecurityConfigurator();
        // Declared first so its more specific pattern wins for /profile.
        $security->firewall('admin', [
            'pattern' => '/profile',
            'authenticators' => ['form_login'],
            'entry_point' => '/login',
        ]);
        $security->firewall('site', [
            'pattern' => '/',
            'authenticators' => ['form_login'],
            'entry_point' => '/login',
            'logout' => ['path' => '/logout', 'target' => '/login'],
        ]);
        // '/' prefix-matches every path; the firewall scope keeps the
        // exemption from waiving the login redirect in the admin firewall.
        $security->publicPath('/', null, ['firewall' => 'site']);

        $this->app()->configureSecurity($security);

        // Anonymous access works inside the scoped firewall ...
        $this->get('/')->assertStatus(200);
        $this->get('/public')->assertStatus(200);

        // ... but requests handled by the other firewall still redirect.
        $this->get('/profile')->assertRedirect('/login');
    }

    public function testUnscopedPublicPathStillAppliesInEveryFirewall(): void
    {
        $security = new SecurityConfigurator();
        $security->firewall('admin', [
            'pattern' => '/public',
            'authenticators' => ['form_login'],
            'entry_point' => '/login',
        ]);
        $security->firewall('site', [
            'pattern' => '/',
            'authenticators' => ['form_login'],
            'entry_point' => '/login',
            'logout' => ['path' => '/logout', 'target' => '/login'],
        ]);
        // No firewall option: pre-existing behavior is unchanged.
        $security->publicPath('/public');

        $this->app()->configureSecurity($security);

        $this->get('/public')->assertStatus(200);
    }

    public function testOtherAccessControlRulesStillApply(): void
    {
        $this->configureSecurity();
        $this->login();

        // johndoe is ROLE_USER; /profile requires ROLE_ADMIN.
        $this->get('/profile')->assertStatus(403);
    }
}
