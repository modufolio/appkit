<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Event\Security;

/**
 * A user confirmed a TOTP secret and two-factor authentication is now on.
 *
 * Dispatched by {@see \Modufolio\Appkit\Security\TwoFactor\TotpService} after
 * the secret and backup codes are flushed. The classic "your security
 * settings changed" mail hangs off this.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final readonly class TwoFactorEnabledEvent
{
    public function __construct(
        public string $userIdentifier,
    ) {
    }
}
