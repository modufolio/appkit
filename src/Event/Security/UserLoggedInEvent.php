<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Event\Security;

/**
 * A user was authenticated on a firewall and the session now carries them.
 *
 * Dispatched once the token is stored and, on a session-backed firewall, the
 * session is migrated and saved — so a listener that reads the session or
 * the token storage sees the logged-in state. The controller has not run
 * yet. A remember-me restore is a login too: `$viaRememberMe` tells it
 * apart from an interactive one, for the "new sign-in" notification that
 * should not fire every time a returning browser presents its cookie.
 *
 * Carries identifiers and names, never the user object or the token: an
 * event is a record of what happened, and a listener that needs the user
 * loads it through the provider.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final readonly class UserLoggedInEvent
{
    /**
     * @param string       $userIdentifier the identifier the user provider knows the user by
     * @param string       $firewallName   the firewall the login happened on
     * @param string       $authenticator  the authenticator's configured name (`form_login`, `remember_me`, …)
     * @param list<string> $roles          the roles on the token
     * @param bool         $viaRememberMe  true when a remember-me cookie signed the user in, not a credential
     * @param string|null  $clientIp       REMOTE_ADDR, when the server provided one
     */
    public function __construct(
        public string $userIdentifier,
        public string $firewallName,
        public string $authenticator,
        public array $roles,
        public bool $viaRememberMe,
        public ?string $clientIp,
    ) {
    }
}
