<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Event\Security;

/**
 * A user's two-factor secret was removed.
 *
 * Dispatched by {@see \Modufolio\Appkit\Security\TwoFactor\TotpService} after
 * the removal is flushed — and only when there was a secret to remove.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final readonly class TwoFactorDisabledEvent
{
    public function __construct(
        public string $userIdentifier,
    ) {
    }
}
