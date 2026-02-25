<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\OAuth;

use Modufolio\Appkit\Security\User\UserInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * OAuth 2.1 Service
 *
 * Handles OAuth 2.1 token generation, validation, and refresh
 * Implements OAuth 2.1 security best practices including:
 * - Short-lived access tokens
 * - Refresh token rotation
 * - Token binding to client
 * - PKCE support (optional)
 */
class OAuthService implements OAuthServiceInterface
{
    private const ACCESS_TOKEN_LIFETIME = 3600; // 1 hour
    private const REFRESH_TOKEN_LIFETIME = 2592000; // 30 days

    public function __construct(
        private EntityManagerInterface $entityManager,
        private OAuthAccessTokenRepositoryInterface $tokenRepository,
        private string $accessTokenEntityClass,
    ) {
    }

    /**
     * Create an access token with optional refresh token
     */
    public function createAccessToken(
        UserInterface $user,
        string $clientId,
        string $grantType,
        array $scopes = [],
        ?ServerRequestInterface $request = null,
        bool $includeRefreshToken = true,
    ): OAuthAccessTokenInterface {
        $token = new $this->accessTokenEntityClass();
        $token->setUser($user);
        $token->setClientId($clientId);
        $token->setGrantType($grantType);
        $token->setScopes($scopes);

        // Generate access token
        $accessToken = $this->generateRandomToken();
        $token->setToken(hash('sha256', $accessToken));

        // Set expiration
        $expiresAt = new \DateTimeImmutable('+' . self::ACCESS_TOKEN_LIFETIME . ' seconds');
        $token->setExpiresAt($expiresAt);

        // Generate refresh token if requested
        if ($includeRefreshToken) {
            $refreshToken = $this->generateRandomToken();
            $token->setRefreshToken(hash('sha256', $refreshToken));

            $refreshExpiresAt = new \DateTimeImmutable('+' . self::REFRESH_TOKEN_LIFETIME . ' seconds');
            $token->setRefreshTokenExpiresAt($refreshExpiresAt);
        }

        // Store request metadata
        if ($request !== null) {
            $serverParams = $request->getServerParams();
            $token->setIpAddress($serverParams['REMOTE_ADDR'] ?? null);
            $token->setUserAgent($request->getHeaderLine('User-Agent'));
        }

        $this->entityManager->persist($token);
        $this->entityManager->flush();

        // Store plain tokens for response
        $token->setPlainAccessToken($accessToken);
        if ($includeRefreshToken) {
            $token->setPlainRefreshToken($refreshToken);
        }

        return $token;
    }

    /**
     * Validate an access token
     */
    public function validateAccessToken(string $accessToken): ?OAuthAccessTokenInterface
    {
        $tokenHash = hash('sha256', $accessToken);
        $token = $this->tokenRepository->findValidToken($tokenHash);

        if ($token === null) {
            return null;
        }

        // Update last used timestamp
        $token->setLastUsedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $token;
    }

    /**
     * Refresh an access token using a refresh token
     * Implements refresh token rotation (OAuth 2.1 requirement)
     */
    public function refreshAccessToken(
        string $refreshToken,
        string $clientId,
        ?ServerRequestInterface $request = null,
    ): ?OAuthAccessTokenInterface {
        $refreshTokenHash = hash('sha256', $refreshToken);
        $oldToken = $this->tokenRepository->findByRefreshToken($refreshTokenHash);

        if ($oldToken === null) {
            return null;
        }

        // Verify token belongs to the correct client
        if ($oldToken->getClientId() !== $clientId) {
            return null;
        }

        // Verify refresh token is not expired
        if ($oldToken->isRefreshTokenExpired()) {
            return null;
        }

        // Verify token is not revoked
        if ($oldToken->isRevoked()) {
            return null;
        }

        // Revoke old token (refresh token rotation)
        $oldToken->revoke();
        $this->entityManager->flush();

        // Create new access token with new refresh token
        return $this->createAccessToken(
            $oldToken->getUser(),
            $clientId,
            'refresh_token',
            $oldToken->getScopes(),
            $request,
            true
        );
    }

    /**
     * Revoke an access token
     */
    public function revokeAccessToken(string $accessToken): bool
    {
        $tokenHash = hash('sha256', $accessToken);
        $token = $this->tokenRepository->findValidToken($tokenHash);

        if ($token === null) {
            return false;
        }

        $token->revoke();
        $this->entityManager->flush();

        return true;
    }

    /**
     * Revoke all tokens for a user
     */
    public function revokeAllUserTokens(UserInterface $user): void
    {
        $this->tokenRepository->revokeAllForUser($user);
    }

    /**
     * Generate a cryptographically secure random token
     */
    private function generateRandomToken(int $length = 64): string
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * Format token response for OAuth 2.1
     */
    public function formatTokenResponse(OAuthAccessTokenInterface $token): array
    {
        $response = [
            'access_token' => $token->getPlainAccessToken(),
            'token_type' => 'Bearer',
            'expires_in' => $token->getExpiresAt()->getTimestamp() - time(),
            'scope' => implode(' ', $token->getScopes()),
        ];

        $plainRefreshToken = $token->getPlainRefreshToken();
        if ($plainRefreshToken !== null) {
            $response['refresh_token'] = $plainRefreshToken;
        }

        return $response;
    }

    /**
     * Clean up expired tokens (for maintenance tasks)
     */
    public function cleanupExpiredTokens(): int
    {
        return $this->tokenRepository->deleteExpiredTokens();
    }
}
