<?php

namespace Modufolio\Appkit\Security;

use Modufolio\Appkit\Security\Token\RememberMeToken;
use Modufolio\Appkit\Security\Token\TokenInterface;

class AuthenticationTrustResolver implements AuthenticationTrustResolverInterface
{
    public function isAuthenticated(#[\SensitiveParameter] ?TokenInterface $token = null): bool
    {
        return $token && $token->getUser();
    }

    public function isRememberMe(#[\SensitiveParameter] ?TokenInterface $token = null): bool
    {
        return $token && $token instanceof RememberMeToken;
    }

    public function isFullFledged(#[\SensitiveParameter] ?TokenInterface $token = null): bool
    {
        return $this->isAuthenticated($token) && !$this->isRememberMe($token);
    }
}
