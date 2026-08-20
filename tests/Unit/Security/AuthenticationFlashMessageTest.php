<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security;

use Modufolio\Appkit\Tests\Case\AppTestCase;

/**
 * The firewall flashes AuthenticationException::getMessageKey() — the
 * user-safe message contract — instead of a hardcoded string. A wrong password
 * reads as 'Invalid credentials.', a CSRF failure as a CSRF failure, and
 * account-status exceptions carry their own text;
 * whether a username exists is never leaked (user-not-found deliberately
 * shares the bad-password message).
 */
class AuthenticationFlashMessageTest extends AppTestCase
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
        ]);
    }

    /** @return list<string> */
    private function errorFlashes(): array
    {
        return array_values($this->app()->session()->getFlashBag()->peek('error'));
    }

    private function attemptLogin(string $email, string $password, ?string $csrf = null): void
    {
        $this->form('/login', [
            'email' => $email,
            'password' => $password,
            '_csrf_token' => $csrf ?? $this->app()->csrfTokenManager()->getToken('authenticate')->getValue(),
        ]);
    }

    public function testWrongPasswordFlashesInvalidCredentials(): void
    {
        $this->attemptLogin('johndoe@example.com', 'wrong-password');

        $this->assertSame(['Invalid credentials.'], $this->errorFlashes());
    }

    public function testUnknownUserIsIndistinguishableFromWrongPassword(): void
    {
        $this->attemptLogin('does-not-exist@example.com', 'whatever');

        $this->assertSame(['Invalid credentials.'], $this->errorFlashes());
    }

    public function testCsrfFailureFlashesItsOwnMessageNotInvalidCredentials(): void
    {
        $this->attemptLogin('johndoe@example.com', 'secret', csrf: 'forged-token');

        $this->assertSame(['Invalid CSRF token.'], $this->errorFlashes());
    }
}
