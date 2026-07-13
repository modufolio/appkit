<?php

namespace Modufolio\Appkit\Tests\Unit\App;

use Modufolio\Appkit\Tests\Case\AppTestCase;
use Psr\Http\Message\ResponseInterface;

class FirewallConfigurationTest extends AppTestCase
{
    public function testFirewallConfiguration(): void
    {
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

        $this->assertSame('main', $this->app()->getFirewallName('/'));
        $this->assertSame('main', $this->app()->getFirewallName('/admin'));

        $config = $this->app()->getFirewallConfig('main');
        $this->assertSame('/', $config['pattern']);
        $this->assertSame(['form_login'], $config['authenticators']);
        $this->assertSame('/login', $config['entry_point']);
    }

    public function testEmptyFirewallConfig(): void
    {
        $this->assertSame([], $this->app()->getFirewallConfig('non_existent'));
    }

    public function testMultipleFirewallConfigurations(): void
    {
        $this->app()->configureFirewall([
            'firewalls' => [
                'api' => [
                    'pattern' => '/api',
                    'stateless' => true,
                    'authenticators' => ['jwt'],
                ],
                'admin' => [
                    'pattern' => '/admin',
                    'authenticators' => ['form_login'],
                    'entry_point' => '/admin/login',
                ],
            ],
        ]);

        $apiConfig = $this->app()->getFirewallConfig('api');
        $this->assertTrue($apiConfig['stateless']);
        $this->assertSame(['jwt'], $apiConfig['authenticators']);

        $adminConfig = $this->app()->getFirewallConfig('admin');
        $this->assertSame('/admin/login', $adminConfig['entry_point']);
        $this->assertSame(['form_login'], $adminConfig['authenticators']);
    }

    public function testHandleAuthenticationReturnsControllerResolverMethodWhenSecurityFalse(): void
    {
        $this->app()->configureFirewall([
            'firewalls' => [
                'public' => [
                    'pattern' => '/public',
                    'security' => false,
                ],
            ],
        ]);

        // No route is registered for /public, so a security=false firewall must
        // reach routing (and 404 via ResourceNotFoundException) instead of being
        // intercepted by the authentication entry point (which would redirect/401).
        $response = $this->get('/public');
        $response->assertStatus(404);
    }

    public function testLogoutFunctionality(): void
    {
        $this->app()->configureFirewall([
            'firewalls' => [
                'main' => [
                    'pattern' => '/',
                    'logout' => ['target' => '/home'],
                ],
            ],
        ]);

        $session = $this->app()->session();
        $session->set('_security_main', 'some_token_data');

        $response = $this->app()->logout('main');

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/home', $response->getHeaderLine('Location'));
    }

    public function testLogoutWithDefaultTarget(): void
    {
        $this->app()->configureFirewall([
            'firewalls' => [
                'main' => ['pattern' => '/'],
            ],
        ]);

        $response = $this->app()->logout('main');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/', $response->getHeaderLine('Location'));
    }

    private function configureFormLoginFirewall(): void
    {
        $this->app()->configureFirewall([
            'firewalls' => [
                'main' => [
                    'pattern' => '/',
                    'authenticators' => ['form_login'],
                    'entry_point' => '/login',
                    'logout' => ['path' => '/logout', 'target' => '/'],
                ],
            ],
        ]);
    }

    public function testGetRequestToLogoutPathDoesNotLogOut(): void
    {
        $this->refreshDatabase();
        $this->loadFixtures();
        $this->configureFormLoginFirewall();
        $this->login();

        $this->assertNotNull($this->app()->session()->get('_security_main'));

        // GET to logout path must NOT log the user out (would be CSRF-vulnerable).
        $this->get('/logout');

        $this->assertNotNull(
            $this->app()->session()->get('_security_main'),
            'Session token should still be present after a GET request to /logout.',
        );
    }

    public function testPostToLogoutWithoutCsrfTokenIsRejected(): void
    {
        $this->refreshDatabase();
        $this->loadFixtures();
        $this->configureFormLoginFirewall();
        $this->login();

        $response = $this->post('/logout', [], ['Content-Type' => 'application/x-www-form-urlencoded']);

        $response->assertStatus(401);
        $this->assertNotNull(
            $this->app()->session()->get('_security_main'),
            'Session token should remain when CSRF token is missing.',
        );
    }

    public function testPostToLogoutWithInvalidCsrfTokenIsRejected(): void
    {
        $this->refreshDatabase();
        $this->loadFixtures();
        $this->configureFormLoginFirewall();
        $this->login();

        $response = $this->post(
            '/logout',
            ['_csrf_token' => 'not-the-real-token'],
            ['Content-Type' => 'application/x-www-form-urlencoded'],
        );

        $response->assertStatus(401);
        $this->assertNotNull(
            $this->app()->session()->get('_security_main'),
            'Session token should remain when CSRF token is invalid.',
        );
    }

    public function testPostToLogoutWithValidCsrfTokenLogsOut(): void
    {
        $this->refreshDatabase();
        $this->loadFixtures();
        $this->configureFormLoginFirewall();
        $this->login();

        $this->logout();

        $this->assertNull(
            $this->app()->session()->get('_security_main'),
            'Session token should be cleared after a valid POST to /logout.',
        );
    }
}
