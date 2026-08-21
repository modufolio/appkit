<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\AccessControl;

use Modufolio\Appkit\Security\AuthenticationTrustResolverInterface;
use Modufolio\Appkit\Security\Exception\AccessDeniedException;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\RoleHierarchy;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Modufolio\Appkit\Security\User\UserInterface;

/**
 * Decides whether a token satisfies an OR-group of authorization attributes,
 * where an attribute is either an ordinary role (checked against the user's
 * reachable roles) or a trust-level attribute (IS_AUTHENTICATED_FULLY,
 * IS_IMPERSONATOR, …) decided by the AuthenticationTrustResolver.
 *
 * Single source of truth for both path-based rules (via RoleConstraint) and
 * #[IsGranted] route groups (via AccessDecisionEngine::enforceRoleGroups), so
 * the two cannot drift apart — and so trust-level attributes work identically
 * wherever they appear.
 *
 * Failure is deliberately split:
 *  - not authenticated at all            → AuthenticationException (log in)
 *  - authenticated but the group needs a FULLER authentication than the current
 *    token (e.g. IS_AUTHENTICATED_FULLY while on a remember-me cookie)
 *                                        → AuthenticationException (step up)
 *  - authenticated, sufficient trust, but the role is simply missing
 *                                        → AccessDeniedException (hard 403)
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class RoleAttributeEvaluator
{
    public function __construct(
        private readonly ?RoleHierarchy $roleHierarchy,
        private readonly AuthenticationTrustResolverInterface $trustResolver,
    ) {
    }

    /**
     * @param list<string> $orGroup any one attribute satisfies the group
     */
    public function isGranted(array $orGroup, #[\SensitiveParameter] ?TokenInterface $token): bool
    {
        if ([] === $orGroup) {
            return true;
        }

        $reachableRoles = $this->reachableRoles($token);

        foreach ($orGroup as $attribute) {
            $trustVerdict = $this->trustResolver->grants($attribute, $token);

            if (true === $trustVerdict) {
                return true;
            }

            // null → not a trust attribute; decide it as an ordinary role.
            if (null === $trustVerdict && in_array($attribute, $reachableRoles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $orGroup
     *
     * @throws AuthenticationException when authentication (or a stronger one) is required
     * @throws AccessDeniedException   when the authenticated user lacks the role
     */
    public function assert(array $orGroup, #[\SensitiveParameter] ?TokenInterface $token, string $context): void
    {
        if ([] === $orGroup || $this->isGranted($orGroup, $token)) {
            return;
        }

        if (!$this->trustResolver->isAuthenticated($token)) {
            throw new AuthenticationException(sprintf('Authentication required for %s', $context));
        }

        // Authenticated but not granted. If the requirement is a stronger
        // authentication than the current token provides, ask the user to step
        // up (a fresh login) rather than hard-denying.
        if (in_array(AuthenticationTrustResolverInterface::IS_AUTHENTICATED_FULLY, $orGroup, true)
            && !$this->trustResolver->isFullFledged($token)) {
            throw new AuthenticationException(sprintf('Full authentication required for %s', $context));
        }

        throw new AccessDeniedException(sprintf('Insufficient rights for %s. Required one of: %s', $context, implode(', ', $orGroup)));
    }

    /**
     * @return list<string>
     */
    private function reachableRoles(#[\SensitiveParameter] ?TokenInterface $token): array
    {
        $user = $token?->getUser();
        if (!$user instanceof UserInterface) {
            return [];
        }

        return $this->roleHierarchy?->getReachableRoles($user->getRoles()) ?? $user->getRoles();
    }
}
