<?php

namespace Modufolio\Appkit\Security;

use Modufolio\Appkit\Security\Token\TokenInterface;

interface AuthenticationTrustResolverInterface
{
    /**
     * Resolves whether the passed token implementation is authenticated.
     */
    public function isAuthenticated(?TokenInterface $token = null): bool;

    /**
     * Resolves whether the passed token implementation is authenticated
     * using remember-me capabilities.
     */
    public function isRememberMe(?TokenInterface $token = null): bool;

    /**
     * Resolves whether the passed token implementation is fully authenticated.
     */
    public function isFullFledged(?TokenInterface $token = null): bool;
}
