<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Event\Security;

/**
 * An impersonating session returned to the administrator's own account.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final readonly class ImpersonationEndedEvent
{
    /**
     * @param string $impersonatorIdentifier the administrator, back in their own session
     * @param string $impersonatedIdentifier the account the session just left
     */
    public function __construct(
        public string $impersonatorIdentifier,
        public string $impersonatedIdentifier,
        public string $firewallName,
        public ?string $clientIp,
    ) {
    }
}
