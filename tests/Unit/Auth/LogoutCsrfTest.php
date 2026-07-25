<?php

namespace Modufolio\Appkit\Tests\Unit\Auth;

use Modufolio\Appkit\Tests\Case\AppTestCase;

/**
 * Logout accepts two equivalent CSRF proofs (mirroring enforceCsrf()):
 * the dedicated 'logout' token as the _csrf_token body field (HTML
 * forms), or the firewall's session token via X-CSRF-Token (SPAs).
 */
class LogoutCsrfTest extends AppTestCase
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
                    'logout' => [
                        'path' => '/logout',
                        'target' => '/',
                    ],
                ],
            ],
        ]);
    }

    public function testLogoutWithBodyTokenSucceeds(): void
    {
        $this->login();

        $logoutToken = $this->app()->csrfTokenManager()->getToken('logout')->getValue();

        $response = $this->post('/logout', ['_csrf_token' => $logoutToken], [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);

        $response->assertRedirect('/');
        $this->assertNull($this->app()->tokenStorage()->getToken());
    }

    public function testLogoutWithCsrfHeaderSucceeds(): void
    {
        $this->login();

        $headerToken = $this->app()->csrfTokenManager()->getToken('csrf')->getValue();

        $response = $this->post('/logout', [], ['X-CSRF-Token' => $headerToken]);

        $response->assertRedirect('/');
        $this->assertNull($this->app()->tokenStorage()->getToken());
    }

    public function testLogoutWithInvalidTokenIsRejected(): void
    {
        $this->login();

        // An explicit bogus header also suppresses the test harness's
        // auto-attached valid X-CSRF-Token.
        $response = $this->post('/logout', ['_csrf_token' => 'bogus'], [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'X-CSRF-Token' => 'bogus',
        ]);

        $this->assertNotSame(302, $response->getResponse()->getStatusCode(), 'logout must not proceed without a valid token');
        // The logout branch runs before the session token is restored into
        // the per-request storage, so check the session itself: the user's
        // security token must survive a rejected logout.
        $this->assertTrue(
            $this->app()->session()->has('_security_main'),
            'the session must still be authenticated after a rejected logout',
        );
    }

    public function testLogoutHeaderRespectsConfiguredCsrfTokenId(): void
    {
        $this->app()->configureFirewall([
            'firewalls' => [
                'main' => [
                    'pattern' => '/',
                    'authenticators' => ['form_login'],
                    'entry_point' => '/login',
                    'csrf_token_id' => 'panel',
                    'logout' => [
                        'path' => '/logout',
                        'target' => '/',
                    ],
                ],
            ],
        ]);

        $this->login();

        // A token minted under the default 'csrf' id must not pass when
        // the firewall validates against 'panel'.
        $wrongId = $this->app()->csrfTokenManager()->getToken('csrf')->getValue();
        $rejected = $this->post('/logout', [], ['X-CSRF-Token' => $wrongId]);
        $this->assertNotSame(302, $rejected->getResponse()->getStatusCode());

        $rightId = $this->app()->csrfTokenManager()->getToken('panel')->getValue();
        $accepted = $this->post('/logout', [], ['X-CSRF-Token' => $rightId]);
        $accepted->assertRedirect('/');
    }
}
