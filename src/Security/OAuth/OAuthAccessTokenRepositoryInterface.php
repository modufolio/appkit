<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\OAuth;

use Modufolio\Appkit\Security\User\UserInterface;

/**
 * Interface for OAuth Access Token Repository
 *
 * Provides the contract for accessing and managing OAuth access tokens.
 * Consuming applications must implement this interface on their repository.
 */
interface OAuthAccessTokenRepositoryInterface
{
    /**
     * Find a valid (non-expired, non-revoked) token by its hash.
     */
    public function findValidToken(string $tokenHash): ?OAuthAccessTokenInterface;

    /**
     * Find a token by its refresh-token hash.
     */
    public function findByRefreshToken(string $refreshTokenHash): ?OAuthAccessTokenInterface;

    /**
     * Revoke all tokens belonging to a user.
     */
    public function revokeAllForUser(UserInterface $user): void;

    /**
     * Delete all expired tokens (maintenance).
     *
     * @return int Number of deleted tokens
     */
    public function deleteExpiredTokens(): int;
}
