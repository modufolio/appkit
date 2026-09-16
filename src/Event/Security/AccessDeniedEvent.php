<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Event\Security;

/**
 * A request was refused by access control.
 *
 * Dispatched before the AccessDeniedException reaches the exception
 * handler, from both the path rules and `#[IsGranted]`. A steady stream of
 * these from one account or address is worth a look; one from an anonymous
 * visitor on a protected path is just the login redirect about to happen,
 * so `$userIdentifier` is what tells the two apart.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final readonly class AccessDeniedEvent
{
    /**
     * @param string      $path           the request path as the rules matched it
     * @param string      $method         the HTTP method
     * @param string|null $userIdentifier the signed-in user, or null for an anonymous request
     * @param string|null $firewallName   the firewall the request fell under
     * @param string      $reason         the exception message: which rule or role refused it
     */
    public function __construct(
        public string $path,
        public string $method,
        public ?string $userIdentifier,
        public ?string $firewallName,
        public string $reason,
        public ?string $clientIp,
    ) {
    }
}
