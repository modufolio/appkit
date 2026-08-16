<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\AccessControl\Constraint;

use Modufolio\Appkit\Security\AccessControl\AccessRule;
use Modufolio\Appkit\Security\AccessControl\RequestMatcher;
use Modufolio\Appkit\Security\AccessControl\RuleConstraintInterface;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;

/**
 * Rejects requests whose HTTP method is outside the rule's `methods` list.
 */
final class MethodConstraint implements RuleConstraintInterface
{
    public function assert(AccessRule $rule, ServerRequestInterface $request, ?TokenInterface $token): void
    {
        if ([] === $rule->methods || $rule->matchesMethod($request->getMethod())) {
            return;
        }

        throw new MethodNotAllowedException($rule->methods, 'Method not allowed for this path: '.RequestMatcher::securityPath($request->getUri()));
    }
}
