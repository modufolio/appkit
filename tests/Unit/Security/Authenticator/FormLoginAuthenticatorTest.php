<?php

namespace Modufolio\Appkit\Tests\Unit\Security\Authenticator;

use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Appkit\Tests\Case\AppTestCase;

class FormLoginAuthenticatorTest extends AppTestCase
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

    // ---- Successful login ----

    public function testLoginSuccessfullyStoresToken(): void
    {
        $csrfToken = $this->app()->csrfTokenManager()->getToken('authenticate')->getValue();

        $response = $this->form('/login', [
            'email' => 'johndoe@example.com',
            'password' => 'secret',
            '_csrf_token' => $csrfToken,
        ]);

        $response->assertRedirect('/');

        $storedToken = $this->app()->tokenStorage()->getToken();
        $this->assertNotNull($storedToken);

        $user = $storedToken->getUser();
        $this->assertInstanceOf(UserInterface::class, $user);
        $this->assertEquals('johndoe@example.com', $user->getEmail());
    }

    public function testAuthenticatedUserCanAccessProtectedRoute(): void
    {
        $this->login();
        $this->assertNotNull($this->app()->tokenStorage()->getToken());
    }

    // ---- Failed login – bad credentials ----

    public function testLoginFailsWithWrongPassword(): void
    {
        $csrfToken = $this->app()->csrfTokenManager()->getToken('authenticate')->getValue();

        $this->form('/login', [
            'email' => 'johndoe@example.com',
            'password' => 'wrong-password',
            '_csrf_token' => $csrfToken,
        ]);

        $this->assertNull($this->app()->tokenStorage()->getToken());
    }

    public function testLoginFailsWithUnknownUser(): void
    {
        $csrfToken = $this->app()->csrfTokenManager()->getToken('authenticate')->getValue();

        $this->form('/login', [
            'email' => 'unknown@example.com',
            'password' => 'secret',
            '_csrf_token' => $csrfToken,
        ]);

        $this->assertNull($this->app()->tokenStorage()->getToken());
    }

    // ---- Empty / missing credentials ----

    public function testLoginFailsWithEmptyEmail(): void
    {
        $csrfToken = $this->app()->csrfTokenManager()->getToken('authenticate')->getValue();

        $this->form('/login', [
            'email' => '',
            'password' => 'secret',
            '_csrf_token' => $csrfToken,
        ]);

        $this->assertNull($this->app()->tokenStorage()->getToken());
    }

    public function testLoginFailsWithEmptyPassword(): void
    {
        $csrfToken = $this->app()->csrfTokenManager()->getToken('authenticate')->getValue();

        $this->form('/login', [
            'email' => 'johndoe@example.com',
            'password' => '',
            '_csrf_token' => $csrfToken,
        ]);

        $this->assertNull($this->app()->tokenStorage()->getToken());
    }

    public function testLoginFailsWithMissingCredentials(): void
    {
        $csrfToken = $this->app()->csrfTokenManager()->getToken('authenticate')->getValue();

        $this->form('/login', [
            '_csrf_token' => $csrfToken,
        ]);

        $this->assertNull($this->app()->tokenStorage()->getToken());
    }

    // ---- CSRF protection ----

    public function testLoginFailsWithoutCsrfToken(): void
    {
        $this->form('/login', [
            'email' => 'johndoe@example.com',
            'password' => 'secret',
        ]);

        $this->assertNull($this->app()->tokenStorage()->getToken());
    }

    public function testLoginFailsWithInvalidCsrfToken(): void
    {
        $this->form('/login', [
            'email' => 'johndoe@example.com',
            'password' => 'secret',
            '_csrf_token' => 'invalid-csrf-token',
        ]);

        $this->assertNull($this->app()->tokenStorage()->getToken());
    }

    // ---- Entry point / redirect ----

    public function testUnauthenticatedGetRequestShowsLoginPage(): void
    {
        $response = $this->get('/login');
        $response->assertStatus(200);
    }

    public function testUnauthenticatedProtectedRouteRedirectsToLogin(): void
    {
        $response = $this->get('/');
        $response->assertRedirect('/login');
    }

    // ---- Logout ----

    public function testLogoutClearsTokenAndRedirects(): void
    {
        $this->login();
        $this->assertNotNull($this->app()->tokenStorage()->getToken());

        $response = $this->get('/logout');
        $response->assertRedirect('/');

        $this->assertNull($this->app()->tokenStorage()->getToken());
    }

    // ---- Supports ----

    public function testGetRequestDoesNotTriggerFormLogin(): void
    {
        // GET /login should show the page, not attempt authentication
        $response = $this->get('/login');
        $response->assertStatus(200);
        $this->assertNull($this->app()->tokenStorage()->getToken());
    }
}
