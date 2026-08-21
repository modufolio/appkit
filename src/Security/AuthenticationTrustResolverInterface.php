<?php

namespace Modufolio\Appkit\Security;

use Modufolio\Appkit\Security\Token\TokenInterface;

/**
 * @author    Maarten Thiebou
 *
 * @see       https://github.com/symfony/security-core
 *
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface AuthenticationTrustResolverInterface
{
    /**
     * Trust-level authorization attributes, usable in access-control rule roles
     * and #[IsGranted] groups. Unlike ROLE_* attributes they are decided by the
     * kind of authentication behind the token, not the user's role list.
     */
    public const IS_AUTHENTICATED_FULLY = 'IS_AUTHENTICATED_FULLY';
    public const IS_AUTHENTICATED_REMEMBERED = 'IS_AUTHENTICATED_REMEMBERED';
    public const IS_AUTHENTICATED = 'IS_AUTHENTICATED';
    public const IS_IMPERSONATOR = 'IS_IMPERSONATOR';

    /**
     * Resolves whether the passed token implementation is authenticated.
     */
    public function isAuthenticated(#[\SensitiveParameter] ?TokenInterface $token = null): bool;

    /**
     * Resolves whether the passed token implementation is authenticated
     * using remember-me capabilities.
     */
    public function isRememberMe(#[\SensitiveParameter] ?TokenInterface $token = null): bool;

    /**
     * Resolves whether the passed token implementation is fully authenticated
     * (an interactive login this request/session, not a remember-me cookie).
     */
    public function isFullFledged(#[\SensitiveParameter] ?TokenInterface $token = null): bool;

    /**
     * Resolves whether the passed token is an impersonation (switch-user) token.
     */
    public function isImpersonator(#[\SensitiveParameter] ?TokenInterface $token = null): bool;

    /**
     * Decide a trust-level attribute against the token.
     *
     * @return bool|null true/false when $attribute is one of the trust-level
     *                   attributes (IS_AUTHENTICATED_*, IS_IMPERSONATOR); null
     *                   when it is not — signalling the caller to fall back to
     *                   ordinary role-membership checks
     */
    public function grants(string $attribute, #[\SensitiveParameter] ?TokenInterface $token = null): ?bool;
}
