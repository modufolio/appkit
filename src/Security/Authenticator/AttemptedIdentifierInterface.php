<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Authenticator;

use Psr\Http\Message\ServerRequestInterface;

/**
 * An authenticator that can say which account a request tried to sign in as.
 *
 * Read only after authentication failed, to put the attempted identifier on
 * the {@see \Modufolio\Appkit\Event\Security\LoginFailedEvent} event — "five
 * failed logins for alice@example.com" is a notification worth sending,
 * "five failed logins" is not. Optional: an authenticator whose credential
 * names no account (an opaque bearer token) simply does not implement it.
 *
 * Return the identifier as the client wrote it, trimmed, or null when the
 * request carried none. Never the password.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface AttemptedIdentifierInterface
{
    public function attemptedIdentifier(ServerRequestInterface $request): ?string;
}
