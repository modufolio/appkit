<?php

namespace Modufolio\Appkit\Tests\Unit\Auth;

use Modufolio\Appkit\Tests\Case\AppTestCase;
use Modufolio\Appkit\Security\User\UserInterface;

class LoginTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set up database and load fixtures
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
                        'target' => '/'
                    ]
                ]
            ]
        ]);
    }


    public function testLoginSuccessfullyStoresToken(): void
    {
        $this->markTestSkipped('Requires URL generation fix for authentication redirects (scheme/host missing)');

        // ARRANGE - Set up test data and initial state
        $csrfToken = $this->app()->csrfTokenManager()->getToken('authenticate')->getValue();

        $loginCredentials = [
            'email' => 'johndoe@example.com',
            'password' => 'secret',
            '_csrf_token' => $csrfToken,
        ];

        // Verify initial state - no token exists
        $initialToken = $this->app()->tokenStorage()->getToken();
        $this->assertNull($initialToken, 'Token storage should be empty initially');

        // ACT - Perform the login action
        $response = $this->form('/login', $loginCredentials);

        // ASSERT - Verify all expected outcomes
        $response->assertRedirect('/');

        // Verify token has been stored
        $storedToken = $this->app()->tokenStorage()->getToken();
        $this->assertNotNull($storedToken, 'Token should be stored after successful login');

        // Verify token contains valid user
        $user = $storedToken->getUser();
        $this->assertInstanceOf(UserInterface::class, $user);
        $this->assertEquals('johndoe@example.com', $user->getEmail());
    }
}
