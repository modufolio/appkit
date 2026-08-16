<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\RememberMe;

/**
 * Server-side store for remember-me tokens (series + rotating value).
 *
 * Enables per-device revocation and cookie-theft detection, which a stateless
 * signature cookie cannot provide. Provide an implementation to the
 * RememberMeAuthenticator via its `token_provider` option to opt in; without
 * one the authenticator stays in stateless-signature mode.
 */
interface RememberMeTokenProviderInterface
{
    /**
     * @return PersistentToken|null the stored token for the series, or null if unknown
     */
    public function loadTokenBySeries(string $series): ?PersistentToken;

    public function createNewToken(PersistentToken $token): void;

    /**
     * Rotate the stored value (and last-used time) for an existing series.
     */
    public function updateExistingToken(string $series, #[\SensitiveParameter] string $tokenValue, int $lastUsed): void;

    public function deleteTokenBySeries(string $series): void;

    /**
     * Revoke every token for a user — used on detected theft (and available for
     * "log out everywhere").
     */
    public function deleteTokensByUserIdentifier(string $userIdentifier): void;
}
