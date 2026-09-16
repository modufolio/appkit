<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Event\Security;

/**
 * An administrator switched their session to another user's account.
 *
 * Dispatched after the switch-user token is stored and the session migrated.
 * Compliance work wants both identities and the moment; that is all this
 * carries.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final readonly class ImpersonationStartedEvent
{
    /**
     * @param string $impersonatorIdentifier the administrator who switched
     * @param string $targetIdentifier       the account now in the session
     */
    public function __construct(
        public string $impersonatorIdentifier,
        public string $targetIdentifier,
        public string $firewallName,
        public ?string $clientIp,
    ) {
    }
}
