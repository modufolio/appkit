<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Authenticator;

use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Exception\UserNotFoundException;
use Modufolio\Appkit\Security\Token\RememberMeToken;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Modufolio\Appkit\Security\User\PasswordAuthenticatedUserInterface;
use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Modufolio\Psr7\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RememberMeAuthenticator extends AbstractAuthenticator
{
    private array $options;

    public function __construct(
        private UserProviderInterface $userProvider,
        array $options = [],
    ) {
        $this->options = array_merge([
            'secret' => null,
            'cookie_name' => 'REMEMBERME',
            'cookie_lifetime' => 2592000,
            'cookie_path' => '/',
            'cookie_domain' => null,
            'cookie_secure' => true,
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
        ], $options);

        if (empty($this->options['secret'])) {
            throw new \InvalidArgumentException('RememberMe secret must be configured.');
        }
    }

    public function supports(ServerRequestInterface $request): bool
    {
        $cookies = $request->getCookieParams();

        return isset($cookies[$this->options['cookie_name']]);
    }

    /**
     * @throws AuthenticationException
     */
    public function authenticate(ServerRequestInterface $request): UserInterface
    {
        $cookies = $request->getCookieParams();
        $cookieValue = $cookies[$this->options['cookie_name']] ?? '';

        if (empty($cookieValue)) {
            throw new AuthenticationException('Remember me cookie is empty.');
        }

        $cookieData = base64_decode($cookieValue, true);
        if (false === $cookieData) {
            throw new AuthenticationException('Invalid remember me cookie format.');
        }

        $parts = explode(':', $cookieData, 3);
        if (3 !== count($parts)) {
            throw new AuthenticationException('Invalid remember me cookie structure.');
        }

        [$identifier, $expires, $hash] = $parts;

        if ((int) $expires < time()) {
            throw new AuthenticationException('Remember me cookie has expired.');
        }

        try {
            $user = $this->userProvider->loadUserByIdentifier($identifier);
        } catch (UserNotFoundException $e) {
            throw new AuthenticationException('User not found for remember me cookie.', 0, $e);
        }

        $expectedHash = $this->generateHash($identifier, (int) $expires, $this->userStateFingerprint($user));
        if (!hash_equals($expectedHash, $hash)) {
            throw new AuthenticationException('Invalid remember me cookie signature.');
        }

        return $user;
    }

    public function createToken(UserInterface $user, string $firewallName): TokenInterface
    {
        return new RememberMeToken($user, $firewallName, $this->options['secret'], $user->getRoles());
    }

    /**
     * Returns a 401 response to satisfy the firewall contract. The remember-me
     * authenticator typically isn't an API entry point, so callers usually
     * fall through to another authenticator instead of surfacing this body.
     *
     * The body is intentionally generic — the cookie-specific failure reason
     * (expired / bad signature / structural error) stays in the log so it
     * doesn't help an attacker probe cookie validity.
     *
     * @throws \JsonException
     */
    public function unauthorizedResponse(ServerRequestInterface $request, AuthenticationException $exception): ResponseInterface
    {
        return Response::json([
            'error' => 'invalid_token',
            'error_description' => 'Authentication required.',
        ], 401);
    }

    public function generateRememberMeCookie(UserInterface $user): string
    {
        $identifier = $user->getUserIdentifier();
        $expires = time() + $this->options['cookie_lifetime'];
        $hash = $this->generateHash($identifier, $expires, $this->userStateFingerprint($user));

        $cookieData = sprintf('%s:%d:%s', $identifier, $expires, $hash);

        return base64_encode($cookieData);
    }

    public function getCookieOptions(): array
    {
        return [
            'expires' => time() + $this->options['cookie_lifetime'],
            'path' => $this->options['cookie_path'],
            'domain' => $this->options['cookie_domain'],
            'secure' => $this->options['cookie_secure'],
            'httponly' => $this->options['cookie_httponly'],
            'samesite' => $this->options['cookie_samesite'],
        ];
    }

    public function getCookieName(): string
    {
        return $this->options['cookie_name'];
    }

    /**
     * Build a Set-Cookie header value that immediately expires the remember-me
     * cookie. Emitted on logout — without it the cookie survives the session
     * invalidation and silently re-authenticates the user on the next request
     * (incomplete logout).
     *
     * Flags mirror getCookieOptions() so the browser matches and overwrites the
     * original cookie rather than setting a second one.
     */
    public function buildClearCookieHeader(): string
    {
        $parts = [
            $this->options['cookie_name'].'=deleted',
            'Path='.$this->options['cookie_path'],
            'Expires=Thu, 01 Jan 1970 00:00:00 GMT',
            'Max-Age=0',
        ];

        if (!empty($this->options['cookie_domain'])) {
            $parts[] = 'Domain='.$this->options['cookie_domain'];
        }
        if ($this->options['cookie_secure']) {
            $parts[] = 'Secure';
        }
        if ($this->options['cookie_httponly']) {
            $parts[] = 'HttpOnly';
        }
        if (!empty($this->options['cookie_samesite'])) {
            $parts[] = 'SameSite='.ucfirst((string) $this->options['cookie_samesite']);
        }

        return implode('; ', $parts);
    }

    /**
     * Derive a per-user fingerprint that changes when the user's password is
     * rotated. Mixing this into the cookie HMAC invalidates outstanding
     * remember-me cookies after a password change without needing a separate
     * revocation table.
     *
     * For users without password authentication, returns an empty string.
     */
    private function userStateFingerprint(UserInterface $user): string
    {
        if ($user instanceof PasswordAuthenticatedUserInterface) {
            $password = $user->getPassword();
            if (null !== $password && '' !== $password) {
                return hash('sha256', $password);
            }
        }

        return '';
    }

    private function generateHash(string $identifier, int $expires, string $userFingerprint): string
    {
        return hash_hmac(
            'sha256',
            sprintf('%s:%d:%s', $identifier, $expires, $userFingerprint),
            $this->options['secret'],
        );
    }
}
