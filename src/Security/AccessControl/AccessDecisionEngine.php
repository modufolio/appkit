<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\AccessControl;

use Modufolio\Appkit\Security\AccessControl\Constraint\ChannelConstraint;
use Modufolio\Appkit\Security\AccessControl\Constraint\IpConstraint;
use Modufolio\Appkit\Security\AccessControl\Constraint\MethodConstraint;
use Modufolio\Appkit\Security\AccessControl\Constraint\RoleConstraint;
use Modufolio\Appkit\Security\Exception\AccessDeniedException;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\RoleHierarchy;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The rule engine behind path- and attribute-based authorization.
 *
 * Owns the one place where access-control rules are interpreted; the three
 * enforcement entry points in the AppSecurity trait (public-path exemption,
 * path rules, #[IsGranted] route attributes) all delegate here, so matching
 * and role semantics cannot drift apart.
 *
 * Rules are validated into AccessRule objects at construction — a malformed
 * rule aborts boot instead of being silently skipped (which would fail open).
 *
 * Enforcement semantics (unchanged from the pre-engine implementation):
 *  - Rules are evaluated in declaration order.
 *  - PUBLIC_ACCESS rules never grant or restrict anything during enforce();
 *    they only waive the login redirect (isPublic()).
 *  - The first non-public rule whose path pattern matches decides the
 *    request: every registered constraint runs against it, then evaluation
 *    stops. Later rules are not consulted.
 *  - A request matching no rule proceeds (authorization abstains; the
 *    firewall still controls authentication).
 *
 * Custom constraints (registerConstraint) run after the built-in ones for
 * every matched rule and read their configuration from AccessRule::$extra.
 */
final class AccessDecisionEngine
{
    /** @var list<AccessRule> */
    private array $rules = [];

    /** @var list<RuleConstraintInterface> */
    private array $constraints;

    /**
     * @param array<int, array<string, mixed>> $rules access-control rules as plain config arrays
     */
    public function __construct(array $rules, private readonly ?RoleHierarchy $roleHierarchy = null)
    {
        foreach ($rules as $rule) {
            $this->rules[] = AccessRule::fromArray($rule);
        }

        // Order matters and mirrors the historical checks: method, channel,
        // IP, then roles — so e.g. a wrong method 405s before a missing
        // login redirects.
        $this->constraints = [
            new MethodConstraint(),
            new ChannelConstraint(),
            new IpConstraint(),
            new RoleConstraint($roleHierarchy),
        ];
    }

    public function registerConstraint(RuleConstraintInterface $constraint): self
    {
        $this->constraints[] = $constraint;

        return $this;
    }

    /**
     * Whether an access-control rule declares this request public.
     *
     * A rule may narrow the exemption to certain methods, so that e.g. a page
     * is readable anonymously while writing to it still requires a login.
     *
     * A rule may also be scoped to one firewall via its `firewall` option, so
     * a broad pattern (a site-wide '/') cannot waive the login redirect for
     * requests handled by a stricter firewall (e.g. an admin panel's).
     */
    public function isPublic(ServerRequestInterface $request, ?string $firewallName = null): bool
    {
        $path = RequestMatcher::securityPath($request->getUri());

        foreach ($this->rules as $rule) {
            if ($rule->isPublic()
                && (null === $rule->firewall || $rule->firewall === $firewallName)
                && $rule->matchesPath($path)
                && $rule->matchesMethod($request->getMethod())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Enforce the path-based access-control rules against this request.
     *
     * @throws AuthenticationException when the matched rule requires a login
     * @throws AccessDeniedException   when the authenticated user is not allowed
     */
    public function enforce(ServerRequestInterface $request, ?TokenInterface $token): void
    {
        $path = RequestMatcher::securityPath($request->getUri());

        foreach ($this->rules as $rule) {
            // A PUBLIC_ACCESS rule only waives the authentication redirect
            // (see isPublic()); it neither grants nor restricts anything
            // here, so later rules still get their say.
            if ($rule->isPublic() || !$rule->matchesPath($path)) {
                continue;
            }

            foreach ($this->constraints as $constraint) {
                $constraint->assert($rule, $request, $token);
            }

            return; // Rule matched and passed
        }
    }

    /**
     * Enforce #[IsGranted] role groups collected from route defaults.
     *
     * Every group (one per #[IsGranted]) must be satisfied (AND); within a
     * group, holding any one of the listed roles is enough (OR).
     *
     * @param array<int, string|array<int, string>> $roleGroups
     *
     * @throws AuthenticationException when no authenticated user is present
     * @throws AccessDeniedException   when a group is not satisfied
     */
    public function enforceRoleGroups(array $roleGroups, ?TokenInterface $token): void
    {
        if ([] === $roleGroups) {
            return;
        }

        if (null === $token || !$token->getUser()) {
            throw new AuthenticationException('Authentication required for this route');
        }

        $user = $token->getUser();
        $userRoles = $this->roleHierarchy?->getReachableRoles($user->getRoles()) ?? $user->getRoles();

        // The (array) cast tolerates a legacy flat list from a stale
        // compiled-route cache.
        foreach ($roleGroups as $group) {
            $group = (array) $group;
            $satisfied = false;

            foreach ($group as $role) {
                if (in_array($role, $userRoles, true)) {
                    $satisfied = true;
                    break;
                }
            }

            if (!$satisfied) {
                throw new AccessDeniedException(sprintf('Insufficient roles for route. Required one of: %s', implode(', ', $group)));
            }
        }
    }
}
