<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\TwoFactor;

use DateTimeImmutable;

/**
 * Interface representing a Two-Factor Authentication Secret
 *
 * Implementations should be immutable or use value object patterns
 */
interface TwoFactorSecret
{
    /**
     * Get the user identifier associated with this secret
     */
    public function getUserIdentifier(): string;

    /**
     * Get the secret key
     */
    public function getSecret(): string;

    /**
     * Check if 2FA is enabled
     */
    public function isEnabled(): bool;


    public function setEnabled(bool $enabled): void;


    public function getEnabledAt(): ?DateTimeImmutable;


    public function setConfirmed(bool $confirmed): void;


    public function setBackupCodes(?array $backupCodes): void;

    public function setPlainBackupCodes(?array $backupCodes): void;

    /**
     * Check if 2FA is confirmed by user
     */
    public function isConfirmed(): bool;


    public function setLastUsedAt(?\DateTimeImmutable $lastUsedAt): void;

    /**
     * Get the last time this secret was used
     */
    public function getLastUsedAt(): ?DateTimeImmutable;


    public function incrementFailedAttempts(): void;


    /**
     * Increment the failed attempts counter
     */
    public function resetFailedAttempts(): void;

    /**
     * Get number of failed attempts
     */
    public function getFailedAttempts(): int;

    /**
     * Check if a backup code exists
     */
    public function hasBackupCode(string $code): bool;

    /**
     * Get all backup codes (hashed)
     *
     * @return array<string>
     */
    public function getBackupCodes(): array;

    public function removeBackupCode(string $code): void;

}
