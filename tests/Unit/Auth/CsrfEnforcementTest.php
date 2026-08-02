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
}
