<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\RememberMe;

/**
 * A server-side remember-me token record (the "series + value" scheme).
 *
 * The cookie carries `series:value`; the server stores the series with a HASH
 * of the current value. On each use the value is rotated, so a stolen cookie
 * and the legitimate cookie diverge — presenting a known series with a stale
 * value is unambiguous proof of theft.
 *
 * $tokenValue is the HASH of the value (never the raw value), so a leak of the
 * store does not yield usable cookies. The same holds for
 * $previousTokenValue: the hash of the value this record last rotated away
 * from, kept acceptable until $previousValueExpiresAt so that requests already
 * in flight when the rotation happened are not mistaken for theft. See
 * RememberMeAuthenticator::PARALLEL_REQUEST_GRACE_SECONDS.
 *
 * @author    Maarten Thiebou
 *
 * @see       https://github.com/symfony/security-core
 *
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class PersistentToken
{
    public function __construct(
        public readonly string $userIdentifier,
        public readonly string $series,
        #[\SensitiveParameter] public readonly string $tokenValue,
        public readonly int $lastUsed,
        /**
         * Hash of the value this record rotated away from, or null for a token
         * that has never rotated.
         */
        #[\SensitiveParameter] public readonly ?string $previousTokenValue = null,
        /**
         * Unix timestamp after which $previousTokenValue is no longer accepted.
         * 0 when there is no previous value.
         */
        public readonly int $previousValueExpiresAt = 0,
    ) {
    }

    /**
     * Whether $candidateHash is this record's previous value and that value is
     * still inside its grace window at $now.
     */
    public function acceptsPreviousValue(#[\SensitiveParameter] string $candidateHash, int $now): bool
    {
        return null !== $this->previousTokenValue
            && $this->previousValueExpiresAt > $now
            && hash_equals($this->previousTokenValue, $candidateHash);
    }
}
