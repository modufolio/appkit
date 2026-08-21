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
 * store does not yield usable cookies.
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
    ) {
    }
}
