<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Event\Security;

/**
 * A persistent remember-me cookie was replayed.
 *
 * A known series arrived with a value that is neither its current one nor
 * the one it just rotated away from: the legitimate browser has moved on,
 * so this copy was taken from it. By the time this is dispatched the
 * authenticator has already revoked every remember-me token the user had,
 * on every device. The event exists so the account owner can be told.
 *
 * The user identifier is the one the stolen series belonged to.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final readonly class RememberMeCookieTheftDetectedEvent
{
    /**
     * @param string|null $userIdentifier the owner of the replayed series, when known
     * @param string|null $clientIp       where the replayed cookie came from
     */
    public function __construct(
        public ?string $userIdentifier,
        public string $firewallName,
        public ?string $clientIp,
    ) {
    }
}
