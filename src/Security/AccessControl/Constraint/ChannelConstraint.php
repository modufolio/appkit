<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\AccessControl\Constraint;

use Modufolio\Appkit\Security\AccessControl\AccessRule;
use Modufolio\Appkit\Security\AccessControl\RuleConstraintInterface;
use Modufolio\Appkit\Security\Exception\InsecureChannelException;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Enforces the rule's `requires_channel` option (currently only "https").
 *
 * An http request to an https-required path is upgraded with a redirect to the
 * https URL (like Symfony's ChannelListener), not hard-denied — the same
 * request over https is legitimate, so bouncing the user to an error page would
 * be wrong. The redirect is carried by InsecureChannelException and issued by
 * the exception handler.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class ChannelConstraint implements RuleConstraintInterface
{
    public function assert(AccessRule $rule, ServerRequestInterface $request, ?TokenInterface $token): void
    {
        if ('https' !== $rule->requiresChannel) {
            return;
        }

        $uri = $request->getUri();
        if ('https' === $uri->getScheme()) {
            return;
        }

        // Same URL over https on the default port. Preserving the path+query
        // means the redirect lands the user exactly where they intended.
        $secureUri = $uri->withScheme('https')->withPort(null);

        throw new InsecureChannelException((string) $secureUri);
    }
}
