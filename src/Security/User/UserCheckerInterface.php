<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Security\User;

use Modufolio\Appkit\Security\Exception\AccountStatusException;

/**
 * Interface for checking user account status during authentication
 * For SOC 2 compliance: CC6.1 - Logical and Physical Access Controls
 */
interface UserCheckerInterface
{
    /**
     * Checks the user account before authentication (pre-auth checks)
     * Verifies account status, expiration, and lock status
     *
     * @throws AccountStatusException if the account cannot authenticate
     */
    public function checkPreAuth(UserInterface $user): void;

    /**
     * Checks the user account after authentication (post-auth checks)
     * Verifies credentials expiration and other post-login requirements
     *
     * @throws AccountStatusException if there are post-auth issues
     */
    public function checkPostAuth(UserInterface $user): void;
}
