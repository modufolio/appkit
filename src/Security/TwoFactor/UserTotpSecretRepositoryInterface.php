<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\TwoFactor;

use Modufolio\Appkit\Security\User\UserInterface;

/**
 * Interface for TOTP Secret Repository
 *
 * Provides contract for accessing and managing user TOTP secrets
 */
interface UserTotpSecretRepositoryInterface
{
    /**
     * Find TOTP secret by user
     */
    public function findByUser(UserInterface $user): ?UserTotpSecretInterface;

    /**
     * Check if user has 2FA enabled
     */
    public function isEnabledForUser(UserInterface $user): bool;
}
