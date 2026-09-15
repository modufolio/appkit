<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\AccessControl\Constraint;

use Modufolio\Appkit\Security\AccessControl\AccessRule;
use Modufolio\Appkit\Security\AccessControl\RequestMatcher;
use Modufolio\Appkit\Security\AccessControl\RuleConstraintInterface;
use Modufolio\Appkit\Security\Exception\AccessDeniedException;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Restricts a rule to the client IPs (or CIDR ranges) in its `ips` list.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class IpConstraint implements RuleConstraintInterface
{
    public function assert(AccessRule $rule, ServerRequestInterface $request, ?TokenInterface $token): void
    {
        if ([] === $rule->ips) {
            return;
        }

        // No client address is not loopback: a runtime that leaves
        // REMOTE_ADDR unset must fail the rule, as firewall selection does.
        $clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        if (!is_string($clientIp) || !IpUtils::checkIp($clientIp, $rule->ips)) {
            throw new AccessDeniedException('Access denied due to IP restriction for path: '.RequestMatcher::securityPath($request->getUri()));
        }
    }
}
