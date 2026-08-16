<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\AccessControl\Constraint;

use Modufolio\Appkit\Security\AccessControl\AccessRule;
use Modufolio\Appkit\Security\AccessControl\RequestMatcher;
use Modufolio\Appkit\Security\AccessControl\RuleConstraintInterface;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Enforces the rule's `requires_channel` option (currently only "https").
 */
final class ChannelConstraint implements RuleConstraintInterface
{
    public function assert(AccessRule $rule, ServerRequestInterface $request, ?TokenInterface $token): void
    {
        if ('https' === $rule->requiresChannel && 'https' !== $request->getUri()->getScheme()) {
            throw new AuthenticationException('HTTPS required for this path: '.RequestMatcher::securityPath($request->getUri()));
        }
    }
}
