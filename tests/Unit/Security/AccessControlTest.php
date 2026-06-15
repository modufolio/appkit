<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security;

use Modufolio\Appkit\Tests\Case\AppTestCase;

/**
 * Functional coverage of the path-based access-control enforcer, focused on the
 * authentication-vs-authorization distinction: an unauthenticated request is
 * sent to the firewall entry point to log in (302), while an authenticated user
 * who simply lacks the required role gets a hard 403 — they are not bounced
 * back to the login page. The fixture user johndoe is a plain ROLE_USER, so
 * /profile (ROLE_ADMIN) denies.
 */
class AccessControlTest extends AppTestCase
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
                    'logout' => ['path' => '/logout', 'target' => '/'],
                ],
            ],
            'access_control' => [
                ['path' => '/profile', 'roles' => ['ROLE_ADMIN']],
            ],
        ]);
    }

    public function testUnauthenticatedRequestRedirectsToLogin(): void
    {
        // Not authenticated → start authentication at the entry point.
        $this->get('/profile')->assertStatus(302);
    }

    public function testAuthenticatedUserWithoutRoleIsForbidden(): void
    {
        // johndoe is a plain ROLE_USER, so the ROLE_ADMIN requirement is not met.
        $this->actingAs('johndoe@example.com', 'secret');

        $this->get('/profile')->assertStatus(403);
    }
}
