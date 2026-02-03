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
                        'target' => '/'
                    ]
                ]
            ]
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
                    'authenticators' => ['jwt']
                ],
                'admin' => [
                    'pattern' => '/admin',
                    'authenticators' => ['form_login'],
                    'entry_point' => '/admin/login'
                ]
            ]
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
        $this->markTestSkipped('Requires exception handler to be enabled to convert ResourceNotFoundException to 404');

        $this->app()->configureFirewall([
            'firewalls' => [
                'public' => [
                    'pattern' => '/public',
                    'security' => false,
                ]
            ]
        ]);

        $response = $this->get('/public');
        $response->assertStatus(404);
    }

    public function testLogoutFunctionality(): void
    {
        $this->app()->configureFirewall([
            'firewalls' => [
                'main' => [
                    'pattern' => '/',
                    'logout' => ['target' => '/home']
                ]
            ]
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
                'main' => ['pattern' => '/']
            ]
        ]);

        $response = $this->app()->logout('main');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/', $response->getHeaderLine('Location'));
    }

    public function testCsrfTokenGeneration(): void
    {
        $token = $this->app()->generateCsrfToken('main');

        $this->assertIsString($token);
        $this->assertSame(32, strlen($token));

        $this->assertSame($token, $this->app()->session()->get('_csrf/logout_main'));
    }

    public function testCsrfTokenUniqueness(): void
    {
        $token1 = $this->app()->generateCsrfToken('main');
        $token2 = $this->app()->generateCsrfToken('main');

        $this->assertNotSame($token1, $token2);
    }
}
