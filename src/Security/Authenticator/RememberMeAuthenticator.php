<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Authenticator;

use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Exception\CookieTheftException;
use Modufolio\Appkit\Security\Exception\UserNotFoundException;
use Modufolio\Appkit\Security\RememberMe\PersistentToken;
use Modufolio\Appkit\Security\RememberMe\RememberMeTokenProviderInterface;
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
    /** @var array<string, mixed> */
    private array $options;

    /**
     * Set during a persistent-mode authenticate() when the token value is
     * rotated: the fresh Set-Cookie header the firewall must attach to the
     * response so the browser stores the new value. Null in signature mode or
     * when nothing was rotated.
     */
    private ?string $pendingCookieHeader = null;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private UserProviderInterface $userProvider,
        array $options = [],
        private ?RememberMeTokenProviderInterface $tokenProvider = null,
    ) {
        $this->options = array_merge([
            'secret' => null,
            'cookie_name' => 'REMEMBERME',
            'cookie_lifetime' => 2_592_000,
            'cookie_path' => '/',
            'cookie_domain' => null,
            'cookie_secure' => true,
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            // Login-form field that opts a user into a persistent session. The
            // firewall reads this on interactive login success to decide whether
            // to auto-issue the cookie (mirrors Symfony's `_remember_me`).
            'remember_parameter' => '_remember_me',
        ], $options);

        if (empty($this->options['secret'])) {
            throw new \InvalidArgumentException('RememberMe secret must be configured.');
        }
    }

    /**
     * Whether this authenticator persists tokens server-side (series + rotating
     * value) — enabling per-device revocation and cookie-theft detection —
     * rather than relying on a stateless signature.
     */
    public function isPersistent(): bool
    {
        return null !== $this->tokenProvider;
    }

    /**
     * The rotated cookie the firewall must re-send after a persistent-mode
     * restore, or null if nothing rotated this request. Consumed once.
     */
    public function consumePendingCookieHeader(): ?string
    {
        $header = $this->pendingCookieHeader;
        $this->pendingCookieHeader = null;

        return $header;
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

        if (null !== $this->tokenProvider) {
            return $this->authenticatePersistent($cookieData, $this->tokenProvider);
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

    /**
     * Persistent-mode authentication (series + rotating value).
     *
     * A known series presented with a stale value is unambiguous cookie theft:
     * the legitimate client rotated the value on its last use, so a mismatch
     * means someone replayed an old copy. We revoke every token for the user
     * (logging out all devices) and raise CookieTheftException. On success the
     * value is rotated and a fresh cookie is queued for the response.
     *
     * @throws AuthenticationException
     */
    private function authenticatePersistent(string $cookieData, RememberMeTokenProviderInterface $tokenProvider): UserInterface
    {
        $parts = explode(':', $cookieData, 2);
        if (2 !== count($parts) || '' === $parts[0] || '' === $parts[1]) {
            throw new AuthenticationException('Invalid remember me cookie structure.');
        }

        [$series, $value] = $parts;

        $token = $tokenProvider->loadTokenBySeries($series);
        if (null === $token) {
            throw new AuthenticationException('Remember me token not found.');
        }

        if (!hash_equals($token->tokenValue, $this->hashValue($value))) {
            // Theft: known series, wrong value. Revoke everything for this user.
            $tokenProvider->deleteTokensByUserIdentifier($token->userIdentifier);

            throw new CookieTheftException('Remember me cookie theft detected.');
        }

        if ($token->lastUsed + (int) $this->options['cookie_lifetime'] < time()) {
            $tokenProvider->deleteTokenBySeries($series);

            throw new AuthenticationException('Remember me cookie has expired.');
        }

        try {
            $user = $this->userProvider->loadUserByIdentifier($token->userIdentifier);
        } catch (UserNotFoundException $e) {
            $tokenProvider->deleteTokenBySeries($series);

            throw new AuthenticationException('User not found for remember me cookie.', 0, $e);
        }

        // Rotate the value on every use so a replayed copy is detectable.
        $newValue = $this->randomValue();
        $tokenProvider->updateExistingToken($series, $this->hashValue($newValue), time());
        $this->pendingCookieHeader = $this->buildSetCookieHeader($this->encodeCookie($series, $newValue));

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
        // Persistent mode: mint a new series + value and store the value hash.
        if (null !== $this->tokenProvider) {
            $series = $this->randomValue();
            $value = $this->randomValue();
            $this->tokenProvider->createNewToken(new PersistentToken(
                userIdentifier: $user->getUserIdentifier(),
                series: $series,
                tokenValue: $this->hashValue($value),
                lastUsed: time(),
            ));

            return $this->encodeCookie($series, $value);
        }

        $identifier = $user->getUserIdentifier();
        $expires = time() + $this->options['cookie_lifetime'];
        $hash = $this->generateHash($identifier, $expires, $this->userStateFingerprint($user));

        $cookieData = sprintf('%s:%d:%s', $identifier, $expires, $hash);

        return base64_encode($cookieData);
    }

    /**
     * A URL-safe, colon-free high-entropy value (usable as a cookie series or
     * value without clashing with the ':' field separator).
     */
    private function randomValue(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function hashValue(#[\SensitiveParameter] string $value): string
    {
        return hash('sha256', $value);
    }

    private function encodeCookie(string $series, #[\SensitiveParameter] string $value): string
    {
        return base64_encode($series.':'.$value);
    }

    /**
     * @return array<string, mixed>
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

    public function getCookieName(): string
    {
        return $this->options['cookie_name'];
    }

    /**
     * The login-form field name that opts into a persistent session.
     */
    public function getRememberParameter(): string
    {
        return $this->options['remember_parameter'];
    }

    /**
     * Build a Set-Cookie header value carrying an already-signed cookie value.
     *
     * The counterpart to buildClearCookieHeader(): the flags are identical, so
     * the cookie issued here is later matched and overwritten by the logout
     * clear-cookie header rather than lingering as a second cookie.
     */
    public function buildSetCookieHeader(string $value): string
    {
        $expires = time() + $this->options['cookie_lifetime'];

        $parts = [
            $this->options['cookie_name'].'='.$value,
            'Path='.$this->options['cookie_path'],
            'Max-Age='.$this->options['cookie_lifetime'],
            'Expires='.gmdate('D, d M Y H:i:s', $expires).' GMT',
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
     * Convenience wrapper: sign a fresh cookie for the user and wrap it in a
     * Set-Cookie header, ready to attach to a response.
     */
    public function buildRememberMeCookieHeader(UserInterface $user): string
    {
        return $this->buildSetCookieHeader($this->generateRememberMeCookie($user));
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
