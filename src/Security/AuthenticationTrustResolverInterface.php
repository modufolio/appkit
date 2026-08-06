<?php

namespace Modufolio\Appkit\Security;

use Modufolio\Appkit\Security\Token\TokenInterface;

interface AuthenticationTrustResolverInterface
{
    /**
     * Resolves whether the passed token implementation is authenticated.
     */
    public function isAuthenticated(#[\SensitiveParameter] ?TokenInterface $token = null): bool;

    /**
     * Resolves whether the passed token implementation is authenticated
     * using remember-me capabilities.
     */
    public function isRememberMe(#[\SensitiveParameter] ?TokenInterface $token = null): bool;

    /**
     * Resolves whether the passed token implementation is fully authenticated.
     */
    public function isFullFledged(#[\SensitiveParameter] ?TokenInterface $token = null): bool;
}
