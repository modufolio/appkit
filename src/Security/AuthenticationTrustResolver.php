<?php

namespace Modufolio\Appkit\Security;

use Modufolio\Appkit\Security\Token\RememberMeToken;
use Modufolio\Appkit\Security\Token\SwitchUserToken;
use Modufolio\Appkit\Security\Token\TokenInterface;

class AuthenticationTrustResolver implements AuthenticationTrustResolverInterface
{
    public function isAuthenticated(#[\SensitiveParameter] ?TokenInterface $token = null): bool
    {
        return (bool) ($token && $token->getUser());
    }

    public function isRememberMe(#[\SensitiveParameter] ?TokenInterface $token = null): bool
    {
        return $token instanceof RememberMeToken;
    }

    public function isFullFledged(#[\SensitiveParameter] ?TokenInterface $token = null): bool
    {
        return $this->isAuthenticated($token) && !$this->isRememberMe($token);
    }

    public function isImpersonator(#[\SensitiveParameter] ?TokenInterface $token = null): bool
    {
        return $token instanceof SwitchUserToken;
    }

    public function grants(string $attribute, #[\SensitiveParameter] ?TokenInterface $token = null): ?bool
    {
        return match ($attribute) {
            self::IS_AUTHENTICATED_FULLY => $this->isFullFledged($token),
            // "remembered" is satisfied by a full login too — a stronger
            // authentication always covers a weaker requirement.
            self::IS_AUTHENTICATED_REMEMBERED => $this->isFullFledged($token) || $this->isRememberMe($token),
            self::IS_AUTHENTICATED => $this->isAuthenticated($token),
            self::IS_IMPERSONATOR => $this->isImpersonator($token),
            default => null,
        };
    }
}
