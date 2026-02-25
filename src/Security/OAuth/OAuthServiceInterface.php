<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\OAuth;

use Modufolio\Appkit\Security\User\UserInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Interface for OAuth 2.1 Service
 *
 * Defines the contract for OAuth token lifecycle management:
 * creation, validation, refresh (with rotation), revocation, and cleanup.
 */
interface OAuthServiceInterface
{
    /**
     * Create an access token with optional refresh token.
     */
    public function createAccessToken(
        UserInterface $user,
        string $clientId,
        string $grantType,
        array $scopes = [],
        ?ServerRequestInterface $request = null,
        bool $includeRefreshToken = true,
    ): OAuthAccessTokenInterface;

    /**
     * Validate an access token string and return the token entity,
     * or null if the token is invalid / expired / revoked.
     */
    public function validateAccessToken(string $accessToken): ?OAuthAccessTokenInterface;

    /**
     * Refresh an access token using a refresh token.
     * Implements refresh-token rotation (OAuth 2.1 requirement).
     */
    public function refreshAccessToken(
        string $refreshToken,
        string $clientId,
        ?ServerRequestInterface $request = null,
    ): ?OAuthAccessTokenInterface;

    /**
     * Revoke an access token.
     *
     * @return bool True if the token was found and revoked.
     */
    public function revokeAccessToken(string $accessToken): bool;

    /**
     * Revoke all tokens for a user.
     */
    public function revokeAllUserTokens(UserInterface $user): void;

    /**
     * Format a token entity into an OAuth 2.1 token response array.
     */
    public function formatTokenResponse(OAuthAccessTokenInterface $token): array;

    /**
     * Clean up expired tokens (maintenance).
     *
     * @return int Number of deleted tokens
     */
    public function cleanupExpiredTokens(): int;
}
