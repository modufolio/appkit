<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Event\Security;

/**
 * An interactive login attempt was refused.
 *
 * Dispatched from the authenticate step when an authenticator that
 * supported the request threw an AuthenticationException: bad credentials,
 * a locked or disabled account, too many attempts. A remember-me cookie that
 * no longer validates is not a failed login and does not raise this; nor
 * does a request no authenticator supported.
 *
 * `$reason` is the exception's class name, which is the safe part of it: the
 * message may say more about the account than the visitor was told.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final readonly class LoginFailedEvent
{
    /**
     * @param string|null              $userIdentifier the identifier that was attempted, when the authenticator could say
     * @param string                   $authenticator  the authenticator's configured name
     * @param class-string<\Throwable> $reason         the exception class the authenticator threw
     * @param string|null              $clientIp       REMOTE_ADDR, when the server provided one
     */
    public function __construct(
        public ?string $userIdentifier,
        public string $firewallName,
        public string $authenticator,
        public string $reason,
        public ?string $clientIp,
    ) {
    }
}
