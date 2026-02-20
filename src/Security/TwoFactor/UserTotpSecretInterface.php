<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\TwoFactor;

use Modufolio\Appkit\Security\User\UserInterface;

/**
 * Interface for TOTP Secret Entity
 *
 * Defines contract for managing user TOTP secrets
 */
interface UserTotpSecretInterface
{
    /**
     * Get the associated user
     */
    public function getUser(): UserInterface;

    /**
     * Set the associated user
     */
    public function setUser(UserInterface $user): void;

    /**
     * Get the TOTP secret
     */
    public function getSecret(): string;

    /**
     * Set the TOTP secret
     */
    public function setSecret(string $secret): void;

    /**
     * Check if 2FA is enabled
     */
    public function isEnabled(): bool;

    /**
     * Set enabled status
     */
    public function setEnabled(bool $enabled): void;

    /**
     * Check if 2FA is confirmed
     */
    public function isConfirmed(): bool;

    /**
     * Set confirmed status
     */
    public function setConfirmed(bool $confirmed): void;

    /**
     * Get last used timestamp
     */
    public function getLastUsedAt(): ?\DateTimeImmutable;

    /**
     * Set last used timestamp
     */
    public function setLastUsedAt(\DateTimeImmutable $lastUsedAt): void;

    /**
     * Get failed attempts count
     */
    public function getFailedAttempts(): int;

    /**
     * Increment failed attempts
     */
    public function incrementFailedAttempts(): void;

    /**
     * Reset failed attempts
     */
    public function resetFailedAttempts(): void;

    /**
     * Check if backup code exists
     */
    public function hasBackupCode(string $code): bool;

    /**
     * Get all backup codes
     */
    public function getBackupCodes(): ?array;

    /**
     * Set backup codes
     */
    public function setBackupCodes(array $codes): void;

    /**
     * Remove a backup code
     */
    public function removeBackupCode(string $code): void;
}
