<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security;

use Modufolio\Appkit\Security\Authenticator\RememberMeAuthenticator;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * A remember-me cookie is an ambient credential: the browser sends it without
 * anyone asking. When it stops validating — expired, password changed, secret
 * rotated — the visitor has not failed a login, so the firewall must not treat
 * it like one. It expires the dead cookie and serves the request anonymously.
 */
class RememberMeStaleCookieTest extends AppTestCase
{
    private const SECRET = 'test-remember-me-secret-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshDatabase();
        $this->loadFixtures();

        $this->app()->registerAuthenticator('remember_me', fn () => new RememberMeAuthenticator(
            $this->app()->userProvider(),
            [
                'secret' => self::SECRET,
                // exercised over plain HTTP in tests
                'cookie_secure' => false,
            ],
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

    /**
     * Well-formed and unexpired, but signed with the wrong key — what a browser
     * carries after a password change or a rotated secret.
     */
    private function staleCookie(string $identifier = 'johndoe@example.com'): string
    {
        return base64_encode(sprintf('%s:%d:%s', $identifier, time() + 3600, str_repeat('a', 64)));
    }

    private function rememberMeCookie(ResponseInterface $response): ?string
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (str_starts_with($header, 'REMEMBERME=')) {
                return $header;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function errorFlashes(): array
    {
        return array_values($this->app()->session()->getFlashBag()->peek('error'));
    }

    public function testStaleCookieDoesNotFlashAFailedLogin(): void
    {
        $this->get('/', headers: ['Cookie' => 'REMEMBERME='.$this->staleCookie()]);

        $this->assertSame([], $this->errorFlashes(), 'A dead cookie is not a failed login attempt');
    }

    public function testStaleCookieIsExpiredSoItStopsComingBack(): void
    {
        $response = $this->get('/', headers: ['Cookie' => 'REMEMBERME='.$this->staleCookie()])->getResponse();

        $cookie = $this->rememberMeCookie($response);
        $this->assertNotNull($cookie, 'Expected the dead cookie to be expired');
        $this->assertStringContainsString('REMEMBERME=deleted', $cookie);
        $this->assertStringContainsString('Max-Age=0', $cookie);
    }

    public function testStaleCookieLeavesTheVisitorAnonymous(): void
    {
        $this->get('/', headers: ['Cookie' => 'REMEMBERME='.$this->staleCookie()]);

        $this->assertNull($this->app()->tokenStorage()->getToken());
    }

    public function testUnknownUserInCookieIsHandledTheSameWay(): void
    {
        $response = $this->get('/', headers: [
            'Cookie' => 'REMEMBERME='.$this->staleCookie('deleted-user@example.com'),
        ])->getResponse();

        $this->assertSame([], $this->errorFlashes());
        $this->assertStringContainsString('REMEMBERME=deleted', (string) $this->rememberMeCookie($response));
    }

    public function testStructurallyBrokenCookieIsHandledTheSameWay(): void
    {
        $response = $this->get('/', headers: ['Cookie' => 'REMEMBERME=not-base64-at-all!!'])->getResponse();

        $this->assertSame([], $this->errorFlashes());
        $this->assertStringContainsString('REMEMBERME=deleted', (string) $this->rememberMeCookie($response));
    }

    /**
     * The interactive path keeps its message — someone typed a bad password and
     * should be told so.
     */
    public function testFailedFormLoginStillFlashesInvalidCredentials(): void
    {
        $csrf = $this->app()->csrfTokenManager()->getToken('authenticate')->getValue();

        $this->form('/login', [
            'email' => 'johndoe@example.com',
            'password' => 'wrong-password',
            '_csrf_token' => $csrf,
        ]);

        $this->assertSame(['Invalid credentials.'], $this->errorFlashes());
    }

    /**
     * A failed interactive login invalidates any remember-me cookie on the
     * request.
     */
    public function testFailedFormLoginClearsRememberMeCookie(): void
    {
        $csrf = $this->app()->csrfTokenManager()->getToken('authenticate')->getValue();

        $response = $this->form('/login', [
            'email' => 'johndoe@example.com',
            'password' => 'wrong-password',
            '_csrf_token' => $csrf,
        ])->getResponse();

        $this->assertStringContainsString(
            'REMEMBERME=deleted',
            (string) $this->rememberMeCookie($response),
        );
    }

    /**
     * Stale cookie + successful opt-in re-login in the same request: with
     * duplicate Set-Cookie headers for one name the browser honors the last,
     * so the freshly issued cookie must come after the expiry of the old one.
     */
    public function testFreshLoginWithStaleCookieKeepsTheNewCookie(): void
    {
        $csrf = $this->app()->csrfTokenManager()->getToken('authenticate')->getValue();

        // form() takes no headers, so build the same request by hand with the
        // stale cookie riding along.
        $response = $this->request('POST', '/login', [
            'email' => 'johndoe@example.com',
            'password' => 'secret',
            '_csrf_token' => $csrf,
            '_remember_me' => true,
        ], headers: [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'Cookie' => 'REMEMBERME='.$this->staleCookie(),
        ])->getResponse();

        $rememberMeHeaders = array_values(array_filter(
            $response->getHeader('Set-Cookie'),
            static fn (string $header): bool => str_starts_with($header, 'REMEMBERME='),
        ));

        $this->assertNotEmpty($rememberMeHeaders, 'Expected REMEMBERME Set-Cookie headers');
        $last = end($rememberMeHeaders);
        $this->assertStringNotContainsString(
            'REMEMBERME=deleted',
            (string) $last,
            'The freshly issued cookie must win over the expiry of the stale one',
        );
    }
}
