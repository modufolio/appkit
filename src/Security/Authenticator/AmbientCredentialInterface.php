<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Authenticator;

/**
 * Marks an authenticator whose credential the browser re-attaches on its own,
 * without the page asking — a cookie, or an HTTP Basic realm the browser has
 * cached.
 *
 * Such a credential can be driven cross-site exactly like a session cookie: a
 * third-party page issuing a request to this origin gets it attached for free.
 * The kernel therefore enforces CSRF on a state-changing request that
 * authenticates this way, just as it does on the restored-session path.
 *
 * Credentials a page must attach deliberately — a bearer token, an API key —
 * are NOT ambient: no browser sends them unprompted, so they cannot be driven
 * cross-site and are intentionally exempt.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface AmbientCredentialInterface
{
}
