<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\AccessControl\Constraint;

use Modufolio\Appkit\Security\AccessControl\AccessRule;
use Modufolio\Appkit\Security\AccessControl\RequestMatcher;
use Modufolio\Appkit\Security\AccessControl\RuleConstraintInterface;
use Modufolio\Appkit\Security\Exception\AccessDeniedException;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\RoleHierarchy;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Modufolio\Appkit\Security\User\UserInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Requires an authenticated user holding at least one of the rule's roles.
 *
 * Distinguishes authentication from authorization: no token means the user
 * must log in (AuthenticationException → entry-point redirect), while an
 * authenticated user lacking the role gets a hard 403 (AccessDeniedException).
 */
final class RoleConstraint implements RuleConstraintInterface
{
    public function __construct(private readonly ?RoleHierarchy $roleHierarchy)
    {
    }

    public function assert(AccessRule $rule, ServerRequestInterface $request, ?TokenInterface $token): void
    {
        if ([] === $rule->roles) {
            return;
        }

        $path = RequestMatcher::securityPath($request->getUri());

        if (null === $token) {
            throw new AuthenticationException('Authentication required for path: '.$path);
        }

        $user = $token->getUser();
        if (!$user instanceof UserInterface) {
            throw new AuthenticationException('Invalid user for path: '.$path);
        }

        $userRoles = $this->roleHierarchy?->getReachableRoles($user->getRoles()) ?? $user->getRoles();

        foreach ($rule->roles as $requiredRole) {
            if (in_array($requiredRole, $userRoles, true)) {
                return;
            }
        }

        throw new AccessDeniedException('Insufficient roles for path: '.$path);
    }
}
