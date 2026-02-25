<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App;

use Modufolio\Appkit\Security\OAuth\OAuthAccessTokenInterface;
use Modufolio\Appkit\Security\OAuth\OAuthServiceInterface;
use Modufolio\Appkit\Security\User\UserInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * In-memory OAuthService implementation for testing.
 *
 * Stores tokens in memory; no database or EntityManager required.
 */
class InMemoryOAuthService implements OAuthServiceInterface
{
    /** @var array<string, OAuthAccessTokenInterface> tokenHash => entity */
    private array $tokens = [];

    /** @var array<string, OAuthAccessTokenInterface> refreshTokenHash => entity */
    private array $refreshTokens = [];

    public function createAccessToken(
        UserInterface $user,
        string $clientId,
        string $grantType,
        array $scopes = [],
        ?ServerRequestInterface $request = null,
        bool $includeRefreshToken = true,
    ): OAuthAccessTokenInterface {
        $token = new InMemoryOAuthAccessToken();
        $token->setUser($user);
        $token->setClientId($clientId);
        $token->setGrantType($grantType);
        $token->setScopes($scopes);

        $plainAccessToken = bin2hex(random_bytes(32));
        $token->setToken(hash('sha256', $plainAccessToken));
        $token->setExpiresAt(new \DateTimeImmutable('+1 hour'));

        if ($includeRefreshToken) {
            $plainRefreshToken = bin2hex(random_bytes(32));
            $token->setRefreshToken(hash('sha256', $plainRefreshToken));
            $token->setRefreshTokenExpiresAt(new \DateTimeImmutable('+30 days'));
            $token->setPlainRefreshToken($plainRefreshToken);
            $this->refreshTokens[hash('sha256', $plainRefreshToken)] = $token;
        }

        if ($request !== null) {
            $serverParams = $request->getServerParams();
            $token->setIpAddress($serverParams['REMOTE_ADDR'] ?? null);
            $token->setUserAgent($request->getHeaderLine('User-Agent'));
        }

        $token->setPlainAccessToken($plainAccessToken);
        $this->tokens[hash('sha256', $plainAccessToken)] = $token;

        return $token;
    }

    public function validateAccessToken(string $accessToken): ?OAuthAccessTokenInterface
    {
        if ($this->validateException !== null) {
            throw $this->validateException;
        }

        $hash = hash('sha256', $accessToken);

        if (!isset($this->tokens[$hash])) {
            return null;
        }

        $token = $this->tokens[$hash];

        if ($token->isRevoked()) {
            return null;
        }

        if ($token->getExpiresAt() < new \DateTimeImmutable()) {
            return null;
        }

        $token->setLastUsedAt(new \DateTimeImmutable());

        return $token;
    }

    public function refreshAccessToken(
        string $refreshToken,
        string $clientId,
        ?ServerRequestInterface $request = null,
    ): ?OAuthAccessTokenInterface {
        $hash = hash('sha256', $refreshToken);

        if (!isset($this->refreshTokens[$hash])) {
            return null;
        }

        $oldToken = $this->refreshTokens[$hash];

        if ($oldToken->getClientId() !== $clientId) {
            return null;
        }

        if ($oldToken->isRefreshTokenExpired() || $oldToken->isRevoked()) {
            return null;
        }

        $oldToken->revoke();
        unset($this->refreshTokens[$hash]);

        return $this->createAccessToken(
            $oldToken->getUser(),
            $clientId,
            'refresh_token',
            $oldToken->getScopes(),
            $request,
            true,
        );
    }

    public function revokeAccessToken(string $accessToken): bool
    {
        $hash = hash('sha256', $accessToken);

        if (!isset($this->tokens[$hash])) {
            return false;
        }

        $this->tokens[$hash]->revoke();

        return true;
    }

    public function revokeAllUserTokens(UserInterface $user): void
    {
        foreach ($this->tokens as $token) {
            if ($token->getUser()->getUserIdentifier() === $user->getUserIdentifier()) {
                $token->revoke();
            }
        }
    }

    public function formatTokenResponse(OAuthAccessTokenInterface $token): array
    {
        $response = [
            'access_token' => $token->getPlainAccessToken(),
            'token_type' => 'Bearer',
            'expires_in' => $token->getExpiresAt()->getTimestamp() - time(),
            'scope' => implode(' ', $token->getScopes()),
        ];

        $plainRefresh = $token->getPlainRefreshToken();
        if ($plainRefresh !== null) {
            $response['refresh_token'] = $plainRefresh;
        }

        return $response;
    }

    public function cleanupExpiredTokens(): int
    {
        $now = new \DateTimeImmutable();
        $count = 0;

        foreach ($this->tokens as $hash => $token) {
            if ($token->getExpiresAt() < $now) {
                unset($this->tokens[$hash]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Register a pre-built token (useful for test setup).
     */
    public function addToken(string $plainAccessToken, OAuthAccessTokenInterface $token): void
    {
        $this->tokens[hash('sha256', $plainAccessToken)] = $token;
    }

    private ?\Exception $validateException = null;

    /**
     * Force validateAccessToken() to throw the given exception.
     */
    public function throwOnValidate(\Exception $e): void
    {
        $this->validateException = $e;
    }
}
