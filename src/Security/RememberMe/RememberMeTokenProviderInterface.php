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
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface RememberMeTokenProviderInterface
{
    /**
     * @return PersistentToken|null the stored token for the series, or null if unknown
     */
    public function loadTokenBySeries(string $series): ?PersistentToken;

    public function createNewToken(PersistentToken $token): void;

    /**
     * Rotate a series to $token, but ONLY if the stored value is still
     * $expectedCurrentValue — a compare-and-swap.
     *
     * The condition is what makes concurrent rotation safe. Two requests that
     * arrive together both read the same record and both mint a replacement;
     * without the check the second write would overwrite the first, leaving the
     * value in the winner's cookie orphaned and the user's next request
     * indistinguishable from a replay. With it, exactly one rotation lands: the
     * loser is told so and leaves the caller's cookie alone.
     *
     * Implementations MUST perform the comparison and the write atomically with
     * respect to other callers (a row-level `UPDATE ... WHERE token_value = ?`
     * and its affected-row count, or a held file lock). An implementation that
     * cannot do so reintroduces the race it exists to close.
     *
     * @param string $expectedCurrentValue the value hash the caller read
     *
     * @return bool true when this call rotated the series, false when the
     *              stored value had already moved on (another request won) or
     *              the series is unknown
     */
    public function updateExistingToken(PersistentToken $token, #[\SensitiveParameter] string $expectedCurrentValue): bool;

    public function deleteTokenBySeries(string $series): void;

    /**
     * Revoke every token for a user — used on detected theft (and available for
     * "log out everywhere").
     */
    public function deleteTokensByUserIdentifier(string $userIdentifier): void;
}
