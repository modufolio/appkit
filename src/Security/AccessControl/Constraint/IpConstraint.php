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

        $clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1';

        if (!IpUtils::checkIp($clientIp, $rule->ips)) {
            throw new AccessDeniedException('Access denied due to IP restriction for path: '.RequestMatcher::securityPath($request->getUri()));
        }
    }
}
