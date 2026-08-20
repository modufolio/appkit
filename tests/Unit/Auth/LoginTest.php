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

        $sessionDir = $this->app()->getState()?->getVarDir().'/sessions';
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

    /**
     * CSRF tokens are rotated at login: any token minted pre-authentication
     * (e.g. leaked via a referrer or shared-machine history) must not survive
     * the login. The login flow calls csrfTokenManager()->clear() after a
     * successful migrate(false-attributes-preserved) so pre-auth tokens die.
     */
    public function testLoginRotatesCsrfTokens(): void
    {
        $manager = $this->app()->csrfTokenManager();

        // A pre-login CSRF token the victim's browser holds.
        $preLoginToken = $manager->getToken('csrf')->getValue();
        $this->assertTrue($manager->validateToken('csrf', $preLoginToken), 'token should be valid before login');

        $this->form('/login', [
            'email' => 'johndoe@example.com',
            'password' => 'secret',
            '_csrf_token' => $manager->getToken('authenticate')->getValue(),
        ])->assertRedirect('/');

        // After login the pre-auth token no longer validates.
        $this->assertFalse(
            $this->app()->csrfTokenManager()->validateToken('csrf', $preLoginToken),
            'pre-login CSRF token must be invalid after authentication (rotation).',
        );
    }

    /**
     * A security-relevant change to the user (here: password reset) must
     * invalidate an existing session on the next request — refreshUser() sees
     * the stored token no longer matches the canonical user and forces a
     * re-authentication rather than letting the stale session keep riding.
     */
    public function testPasswordChangeMidSessionForcesReauthentication(): void
    {
        // Establish an authenticated session.
        $this->form('/login', [
            'email' => 'johndoe@example.com',
            'password' => 'secret',
            '_csrf_token' => $this->app()->csrfTokenManager()->getToken('authenticate')->getValue(),
        ])->assertRedirect('/');
        $this->assertNotNull($this->app()->tokenStorage()->getToken(), 'authenticated after login');

        // Simulate an out-of-band password reset: the stored hash changes.
        $em = $this->app()->entityManager();
        $user = $this->app()->userProvider()->loadUserByIdentifier('johndoe@example.com');
        $this->assertInstanceOf(\Modufolio\Appkit\Tests\App\Entity\User::class, $user);
        $user->setPassword(password_hash('a-different-secret', PASSWORD_BCRYPT));
        $em->flush();

        // The next request restores the session token, refreshes the user,
        // detects the change and logs the stale session out.
        $this->get('/');

        $this->assertNull(
            $this->app()->tokenStorage()->getToken(),
            'a security-relevant user change must invalidate the existing session.',
        );
    }
}
