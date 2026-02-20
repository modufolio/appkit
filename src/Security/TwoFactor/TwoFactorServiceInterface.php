<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\TwoFactor;

use Modufolio\Appkit\Security\User\UserInterface;

/**
 * Interface for Two-Factor Authentication Services
 *
 * Defines contract for 2FA implementations (TOTP, SMS, Email, etc.)
 */
interface TwoFactorServiceInterface
{
    /**
     * Generate a new 2FA secret for a user
     *
     * @throws TwoFactorException If 2FA is already enabled
     */
    public function generateSecret(UserInterface $user): TwoFactorSecret;

    /**
     * Verify a 2FA code for a user
     *
     * @throws TwoFactorException If verification fails
     */
    public function verifyCode(TwoFactorSecret $secret, string $code): bool;

    /**
     * Enable 2FA for a user after verifying a code
     *
     * @throws TwoFactorException If code is invalid or 2FA cannot be enabled
     */
    public function enableTwoFactor(TwoFactorSecret $secret, string $code): bool;

    /**
     * Disable 2FA for a user
     */
    public function disableTwoFactor(UserInterface $user): void;

    /**
     * Check if 2FA is enabled for a user
     */
    public function isTwoFactorEnabled(UserInterface $user): bool;

    /**
     * Get 2FA secret for a user
     */
    public function getTwoFactorSecret(UserInterface $user): ?TwoFactorSecret;

    /**
     * Verify a backup code
     *
     * @throws TwoFactorException If backup code is invalid
     */
    public function verifyBackupCode(TwoFactorSecret $secret, string $code): bool;

    /**
     * Regenerate backup codes
     *
     * @return array<string> New backup codes (plaintext, only shown once)
     * @throws TwoFactorException If 2FA is not enabled
     */
    public function regenerateBackupCodes(TwoFactorSecret $secret): array;
}
