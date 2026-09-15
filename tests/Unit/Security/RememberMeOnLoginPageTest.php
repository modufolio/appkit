<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security;

use Modufolio\Appkit\Security\Authenticator\RememberMeAuthenticator;
use Modufolio\Appkit\Tests\Case\AppTestCase;

/**
 * The login page (GET entry_point) is served anonymously, but a remember-me
 * cookie on the request is honoured first — as Symfony's firewall runs its
 * remember-me listener on /login like on any other path — so the login
 * controller sees the returning visitor as signed in and can redirect.
 */
class RememberMeOnLoginPageTest extends AppTestCase
{
    private const SECRET = 'test-remember-me-secret-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshDatabase();
        $this->loadFixtures();

        $this->app()->registerAuthenticator('remember_me', fn () => new RememberMeAuthenticator(
            $this->app()->userProvider(),
            ['secret' => self::SECRET, 'cookie_secure' => false],
        ));

        $this->app()->configureFirewall([
            'firewalls' => [
                'main' => [
                    'pattern' => '/',
                    'authenticators' => ['form_login', 'remember_me'],
                    'entry_point' => '/login',
                    'logout' => ['path' => '/logout', 'target' => '/'],
                ],
            ],
        ]);
    }

    private function cookieFor(string $email, string $secret = self::SECRET): string
    {
        $rememberMe = new RememberMeAuthenticator($this->app()->userProvider(), [
            'secret' => $secret,
            'cookie_secure' => false,
        ]);
        $user = $this->app()->userProvider()->loadUserByIdentifier($email);

        return 'REMEMBERME='.$rememberMe->generateRememberMeCookie($user);
    }

    public function testLoginPageSignsInAVisitorWithAValidCookie(): void
    {
        $this->get('/login', headers: ['Cookie' => $this->cookieFor('johndoe@example.com')]);

        $this->assertSame(
            'johndoe@example.com',
            $this->app()->tokenStorage()->getToken()?->getUser()?->getUserIdentifier(),
            'The login controller must see the remembered user',
        );
    }

    public function testLoginPageStaysAnonymousWithoutACookie(): void
    {
        $this->get('/login')->assertStatus(200);

        $this->assertNull($this->app()->tokenStorage()->getToken());
    }

    public function testLoginPageIsServedWhenTheCookieNoLongerValidates(): void
    {
        $response = $this->get('/login', headers: [
            'Cookie' => $this->cookieFor('johndoe@example.com', 'a-rotated-secret-0123456789abcdef'),
        ]);

        $response->assertStatus(200);
        $this->assertNull($this->app()->tokenStorage()->getToken());

        $expired = array_filter(
            $response->getResponse()->getHeader('Set-Cookie'),
            static fn (string $h): bool => str_starts_with($h, 'REMEMBERME=deleted'),
        );
        $this->assertNotEmpty($expired, 'The dead cookie is expired on the response');
    }
}
