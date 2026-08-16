<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\AccessControl\Constraint;

use Modufolio\Appkit\Security\AccessControl\AccessRule;
use Modufolio\Appkit\Security\AccessControl\RequestMatcher;
use Modufolio\Appkit\Security\AccessControl\RoleAttributeEvaluator;
use Modufolio\Appkit\Security\AccessControl\RuleConstraintInterface;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Requires the token to satisfy at least one of the rule's roles.
 *
 * "Roles" here may be ordinary ROLE_* attributes or trust-level attributes
 * (IS_AUTHENTICATED_FULLY, IS_IMPERSONATOR, …). The decision — and the
 * authn-vs-authz split (log-in/step-up vs hard 403) — is delegated to the
 * shared RoleAttributeEvaluator so path rules and #[IsGranted] behave alike.
 */
final class RoleConstraint implements RuleConstraintInterface
{
    public function __construct(private readonly RoleAttributeEvaluator $evaluator)
    {
    }

    public function assert(AccessRule $rule, ServerRequestInterface $request, ?TokenInterface $token): void
    {
        if ([] === $rule->roles) {
            return;
        }

        $this->evaluator->assert(
            $rule->roles,
            $token,
            'path: '.RequestMatcher::securityPath($request->getUri()),
        );
    }
}
