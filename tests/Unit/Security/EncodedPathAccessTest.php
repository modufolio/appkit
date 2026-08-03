<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security;

use Modufolio\Appkit\Security\SecurityConfigurator;
use Modufolio\Appkit\Tests\Case\AppTestCase;

/**
 * The security pipeline resolves the firewall / access-control decision from
 * the request path, and the router resolves the controller from the SAME
 * request. If the two disagree about what the path is, a request can slip past
 * the firewall yet still reach a protected controller.
 *
 * The Symfony URL matcher rawurldecode()s the path before matching routes, so
 * the security layer must normalize the path the same way — otherwise a single
 * percent-encoded character in a protected prefix (e.g. "/%61pi" for "/api")
 * bypasses the firewall while the controller still runs.
 */
class EncodedPathAccessTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshDatabase();
        $this->loadFixtures();

        // A single firewall guarding the /api prefix. /api/me is a real route
        // (config/routes/test.php) so the router will resolve it.
        $security = new SecurityConfigurator();
        $security->firewall('main', [
            'pattern' => '/api',
            'authenticators' => ['form_login'],
            'entry_point' => '/login',
            'logout' => ['path' => '/logout', 'target' => '/login'],
        ]);

        $this->app()->configureSecurity($security);
    }

    public function testProtectedPathRedirectsAnonymousUser(): void
    {
        // Sanity: the plain path is guarded.
        $this->get('/api/me')->assertRedirect('/login');
    }

    public function testEncodedProtectedPathCannotBypassTheFirewall(): void
    {
        // "%61" decodes to "a", so the router resolves this to /api/me and runs
        // the protected controller. Security must treat it as /api and redirect,
        // exactly like the plain path above.
        $this->get('/%61pi/me')->assertRedirect('/login');
    }
}
