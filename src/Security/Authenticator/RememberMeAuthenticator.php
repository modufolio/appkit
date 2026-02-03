<?php

namespace Modufolio\Appkit\Security\Authenticator;

use Modufolio\Psr7\Http\Response;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Token\RememberMeToken;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RememberMeAuthenticator extends AbstractAuthenticator
{
    private array $options;

    public function __construct(
        private UserProviderInterface $userProvider,
        array $options = []
    ) {
        $this->options = array_merge([
            'secret' => null,
            'cookie_name' => 'REMEMBERME',
            'cookie_lifetime' => 2592000, // 30 days
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

        // Cookie format: base64(identifier:expires:hash)
        $cookieData = base64_decode($cookieValue, true);
        if ($cookieData === false) {
            throw new AuthenticationException('Invalid remember me cookie format.');
        }

        $parts = explode(':', $cookieData, 3);
        if (count($parts) !== 3) {
            throw new AuthenticationException('Invalid remember me cookie structure.');
        }

        [$identifier, $expires, $hash] = $parts;

        // Check expiration
        if ((int) $expires < time()) {
            throw new AuthenticationException('Remember me cookie has expired.');
        }

        // Verify hash
        $expectedHash = $this->generateHash($identifier, (int) $expires);
        if (!hash_equals($expectedHash, $hash)) {
            throw new AuthenticationException('Invalid remember me cookie signature.');
        }

        try {
            $user = $this->userProvider->loadUserByIdentifier($identifier);
        } catch (\Exception $e) {
            throw new AuthenticationException('User not found for remember me cookie.', 0, $e);
        }

        return $user;
    }

    public function createToken(UserInterface $user, string $firewallName): TokenInterface
    {
        return new RememberMeToken($user, $firewallName, $this->options['secret'], $user->getRoles());
    }

    /**
     * @throws \JsonException
     */
    public function unauthorizedResponse(ServerRequestInterface $request, AuthenticationException $exception): ResponseInterface
    {
        // For remember me, we typically don't return an error response
        // Instead, we just let the authentication fail silently and let other authenticators try
        return Response::json(['error' => $exception->getMessage()], 401);
    }

    /**
     * Generate a remember me cookie for a user
     */
    public function generateRememberMeCookie(UserInterface $user): string
    {
        $identifier = $user->getUserIdentifier();
        $expires = time() + $this->options['cookie_lifetime'];
        $hash = $this->generateHash($identifier, $expires);

        $cookieData = sprintf('%s:%d:%s', $identifier, $expires, $hash);
        return base64_encode($cookieData);
    }

    /**
     * Generate the cookie options array for setting the cookie
     */
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

    /**
     * Get the cookie name
     */
    public function getCookieName(): string
    {
        return $this->options['cookie_name'];
    }

    /**
     * Generate hash for remember me cookie
     */
    private function generateHash(string $identifier, int $expires): string
    {
        return hash_hmac('sha256', sprintf('%s:%d', $identifier, $expires), $this->options['secret']);
    }
}
