<?php

namespace Modufolio\Appkit\Tests\Unit\Auth;

use Modufolio\Appkit\Tests\Case\AppTestCase;

/**
 * The harness auto-attaches X-CSRF-Token to authenticated state-changing
 * requests for convenience, which means no other feature test would catch
 * a regression where the firewall's CSRF guard silently stops enforcing.
 * This test opts out via withoutCsrfToken() and proves rejection.
 */
class CsrfEnforcementTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshDatabase();
        $this->loadFixtures();

        $this->app()->configureFirewall([
            'firewalls' => [
                'main' => [
                    'pattern' => '/',
                    'authenticators' => ['form_login'],
                    'entry_point' => '/login',
                ],
            ],
        ]);
    }

    public function testStateChangingRequestWithoutCsrfTokenIsRejected(): void
    {
        $this->login();

        $response = $this->withoutCsrfToken()->post('/api/2fa/setup');

        $this->assertSame(403, $response->getResponse()->getStatusCode());
    }

    public function testStateChangingRequestWithInvalidCsrfTokenIsRejected(): void
    {
        $this->login();

        $response = $this->post('/api/2fa/setup', [], [
            'X-CSRF-Token' => 'not-a-valid-token',
        ]);

        $this->assertSame(403, $response->getResponse()->getStatusCode());
    }

    public function testStateChangingRequestWithValidCsrfTokenSucceeds(): void
    {
        $this->login();

        $response = $this->post('/api/2fa/setup');

        $this->assertSame(200, $response->getResponse()->getStatusCode());
    }

    public function testNamespacedFormTokenAuthorisesTheRequest(): void
    {
        // A Symfony form posts `contact[_token]` keyed by its own token id —
        // declared as a (form name → token id) pair, the kernel accepts it
        // without a csrf_validator closure.
        $this->app()->configureFirewall([
            'firewalls' => [
                'main' => [
                    'pattern' => '/',
                    'authenticators' => ['form_login'],
                    'entry_point' => '/login',
                    'csrf_form_tokens' => ['contact' => 'contact_form'],
                ],
            ],
        ]);

        $this->login();
        $token = $this->app()->csrfTokenManager()->getToken('contact_form')->getValue();

        $response = $this->withoutCsrfToken()->post('/submit', [
            'contact' => ['_token' => $token],
        ], ['Content-Type' => 'application/x-www-form-urlencoded']);

        $this->assertSame(200, $response->getResponse()->getStatusCode());
    }

    public function testForgedNamespacedFormTokenIsRejected(): void
    {
        $this->app()->configureFirewall([
            'firewalls' => [
                'main' => [
                    'pattern' => '/',
                    'authenticators' => ['form_login'],
                    'entry_point' => '/login',
                    'csrf_form_tokens' => ['contact' => 'contact_form'],
                ],
            ],
        ]);

        $this->login();

        // No other proof either: the namespaced check must fall through to the
        // built-in ones, which have nothing to validate → 403.
        $response = $this->withoutCsrfToken()->post('/submit', [
            'contact' => ['_token' => 'forged'],
        ], ['Content-Type' => 'application/x-www-form-urlencoded']);

        $this->assertSame(403, $response->getResponse()->getStatusCode());
    }

    public function testDelegatedPathSkipsTheKernelCsrfCheck(): void
    {
        // A controller that validates its own token (a Symfony form, a
        // component with a per-form token id) declares its path delegated;
        // the kernel steps aside so that layer can answer with its own
        // failure shape instead of a hard 403.
        $this->app()->configureFirewall([
            'firewalls' => [
                'main' => [
                    'pattern' => '/',
                    'authenticators' => ['form_login'],
                    'entry_point' => '/login',
                    'csrf_delegated_paths' => ['/submit'],
                ],
            ],
        ]);

        $this->login();

        $response = $this->withoutCsrfToken()->post('/submit', ['x' => 'y'], [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);

        // Reaches the controller instead of the 403 the undelegated test
        // above proves for other paths.
        $this->assertSame(200, $response->getResponse()->getStatusCode());
    }

    public function testDelegationIsScopedToItsPath(): void
    {
        $this->app()->configureFirewall([
            'firewalls' => [
                'main' => [
                    'pattern' => '/',
                    'authenticators' => ['form_login'],
                    'entry_point' => '/login',
                    'csrf_delegated_paths' => ['/submit'],
                ],
            ],
        ]);

        $this->login();

        // Every other path keeps the kernel check.
        $response = $this->withoutCsrfToken()->post('/api/2fa/setup');

        $this->assertSame(403, $response->getResponse()->getStatusCode());
    }
}
