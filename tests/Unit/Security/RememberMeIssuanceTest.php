<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security;

use Modufolio\Appkit\Security\Authenticator\RememberMeAuthenticator;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Functional coverage of the firewall auto-issuing the remember-me cookie on
 * interactive login success (AppSecurity::issueRememberMeCookie) — the app no
 * longer has to assemble a Set-Cookie header in a controller.
 */
class RememberMeIssuanceTest extends AppTestCase
{
    private const SECRET = 'test-remember-me-secret-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshDatabase();
        $this->loadFixtures();

        $this->registerRememberMe('_remember_me');
        $this->configureMainFirewall();
    }

    private function registerRememberMe(string $parameter): void
    {
        $this->app()->registerAuthenticator('remember_me', fn () => new RememberMeAuthenticator(
            $this->app()->userProvider(),
            [
                'secret' => self::SECRET,
                // exercised over plain HTTP in tests
                'cookie_secure' => false,
                'remember_parameter' => $parameter,
            ],
        ));
    }

    private function configureMainFirewall(): void
    {
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

    private function rememberMeCookie(ResponseInterface $response): ?string
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (str_starts_with($header, 'REMEMBERME=')) {
                return $header;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function attemptLogin(array $extra = []): ResponseInterface
    {
        $csrf = $this->app()->csrfTokenManager()->getToken('authenticate')->getValue();

        return $this->form('/login', array_merge([
            'email' => 'johndoe@example.com',
            'password' => 'secret',
            '_csrf_token' => $csrf,
        ], $extra))->getResponse();
    }

    public function testCookieIsIssuedWhenOptedIn(): void
    {
        $response = $this->attemptLogin(['_remember_me' => true]);

        $cookie = $this->rememberMeCookie($response);
        $this->assertNotNull($cookie, 'Expected an auto-issued REMEMBERME cookie');
        $this->assertStringContainsString('HttpOnly', $cookie);
        $this->assertStringContainsString('Max-Age=2592000', $cookie);
        $this->assertStringContainsString('SameSite=Lax', $cookie);
        // cookie_secure=false in this test, so no Secure flag
        $this->assertStringNotContainsString('Secure', $cookie);
    }

    public function testNoCookieWhenNotOptedIn(): void
    {
        $this->assertNull($this->rememberMeCookie($this->attemptLogin()));
    }

    public function testFalseyOptInDoesNotIssueCookie(): void
    {
        $this->assertNull($this->rememberMeCookie($this->attemptLogin(['_remember_me' => false])));
        $this->assertNull($this->rememberMeCookie($this->attemptLogin(['_remember_me' => '0'])));
    }

    public function testIssuedCookieSignsAReturningVisitorBackIn(): void
    {
        // Mint a cookie exactly as the firewall would, then present it on a
        // fresh request that carries no session.
        $rememberMe = new RememberMeAuthenticator($this->app()->userProvider(), [
            'secret' => self::SECRET,
            'cookie_secure' => false,
        ]);
        $user = $this->app()->userProvider()->loadUserByIdentifier('johndoe@example.com');
        $value = $rememberMe->generateRememberMeCookie($user);

        $this->get('/', headers: ['Cookie' => 'REMEMBERME='.$value]);

        $this->assertSame(
            'johndoe@example.com',
            $this->app()->tokenStorage()->getToken()?->getUser()?->getUserIdentifier(),
        );
    }

    public function testRestorePathDoesNotReIssueCookie(): void
    {
        $rememberMe = new RememberMeAuthenticator($this->app()->userProvider(), [
            'secret' => self::SECRET,
            'cookie_secure' => false,
        ]);
        $user = $this->app()->userProvider()->loadUserByIdentifier('johndoe@example.com');
        $value = $rememberMe->generateRememberMeCookie($user);

        // A plain navigation request authenticated purely by the cookie must not
        // trigger a fresh Set-Cookie (the RememberMeToken branch is skipped).
        $response = $this->get('/', headers: ['Cookie' => 'REMEMBERME='.$value])->getResponse();

        $this->assertNull($this->rememberMeCookie($response));
    }

    public function testCustomRememberParameterIsHonored(): void
    {
        // Reconfigure with the parameter the appkit-portfolio uses.
        $this->registerRememberMe('remember');
        $this->configureMainFirewall();

        $this->assertNotNull($this->rememberMeCookie($this->attemptLogin(['remember' => true])));
        // the default parameter no longer opts in
        $this->assertNull($this->rememberMeCookie($this->attemptLogin(['_remember_me' => true])));
    }
}
