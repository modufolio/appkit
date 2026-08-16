<?php

namespace Modufolio\Appkit\Tests\Unit\Auth;

use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Appkit\Tests\Case\AppTestCase;

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
                        'target' => '/',
                    ],
                ],
            ],
        ]);
    }

    public function testLoginRegeneratesSessionIdToPreventFixation(): void
    {
        // Pre-set a session ID before login (simulating session fixation).
        $session = $this->app()->session();
        $session->start();
        $idBefore = $session->getId();
        $this->assertNotEmpty($idBefore);

        $csrfToken = $this->app()->csrfTokenManager()->getToken('authenticate')->getValue();

        $this->form('/login', [
            'email' => 'johndoe@example.com',
            'password' => 'secret',
            '_csrf_token' => $csrfToken,
        ]);

        $idAfter = $this->app()->session()->getId();

        $this->assertNotEmpty($idAfter);
        $this->assertNotSame(
            $idBefore,
            $idAfter,
            'Session ID must be regenerated on successful login (defense against session fixation).',
        );
    }

    /**
     * The teeth behind fixation defense: rotating the ID is not enough — the
     * pre-login session storage must be DELETED, or an attacker who fixed that
     * ID can keep using it as an authenticated session after the victim logs
     * in. This is why the login flow calls migrate(true), not migrate(false):
     * the boolean deletes the old storage (attributes are carried over either
     * way). See AppSecurity::handleAuthentication.
     */
    public function testLoginDestroysPreLoginSessionStorage(): void
    {
        $session = $this->app()->session();
        $session->start();
        $idBefore = $session->getId();

        // Materialise the pre-login session on disk (a fixed session an
        // attacker would have planted).
        $session->set('probe', 'fixed');
        $session->save();

        $sessionDir = $this->app()->getState()->getBaseDir().'/var/sessions';
        $oldFile = $sessionDir.'/sess_'.$idBefore;
        $this->assertFileExists($oldFile, 'pre-login session file should exist before login');

        $csrfToken = $this->app()->csrfTokenManager()->getToken('authenticate')->getValue();
        $this->form('/login', [
            'email' => 'johndoe@example.com',
            'password' => 'secret',
            '_csrf_token' => $csrfToken,
        ])->assertRedirect('/');

        // ID rotated (fixation defense) ...
        $this->assertNotSame($idBefore, $this->app()->session()->getId());
        // ... and the old storage is gone, so the fixed ID cannot be replayed.
        $this->assertFileDoesNotExist(
            $oldFile,
            'pre-login session storage must be destroyed on login (migrate(true)).',
        );
    }

    public function testLoginSuccessfullyStoresToken(): void
    {
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
