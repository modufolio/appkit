<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Event\Security;

/**
 * A user's session on a firewall was ended.
 *
 * Dispatched by the kernel's logout after the session is invalidated and the
 * remember-me cookies are revoked — whether the user asked for it, or the
 * kernel signed them out because their account no longer checks out or the
 * session sat idle too long. Not dispatched when nobody was signed in.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final readonly class UserLoggedOutEvent
{
    /**
     * @param string|null $clientIp REMOTE_ADDR, when the server provided one
     */
    public function __construct(
        public string $userIdentifier,
        public string $firewallName,
        public ?string $clientIp,
    ) {
    }
}
