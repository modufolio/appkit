<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\AccessControl;

use Modufolio\Appkit\Security\Token\TokenInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * One constraint an access-control rule can impose on a matched request.
 *
 * The AccessDecisionEngine runs every registered constraint against each
 * rule that matches the request path. A constraint that the rule does not
 * configure (e.g. no `ips` key for the IP constraint) must return without
 * effect; a violated constraint throws the appropriate security exception.
 *
 * Custom constraints read their configuration from AccessRule::$extra:
 *
 * ```php
 * final class OfficeHoursConstraint implements RuleConstraintInterface
 * {
 *     public function assert(AccessRule $rule, ServerRequestInterface $request, ?TokenInterface $token): void
 *     {
 *         if (($rule->extra['office_hours'] ?? false) && !$this->withinOfficeHours()) {
 *             throw new AccessDeniedException('Outside office hours.');
 *         }
 *     }
 * }
 * ```
 */
interface RuleConstraintInterface
{
    /**
     * @throws \Throwable when the request violates the constraint
     */
    public function assert(AccessRule $rule, ServerRequestInterface $request, ?TokenInterface $token): void;
}
