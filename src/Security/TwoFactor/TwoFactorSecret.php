<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\TwoFactor;

/**
 * Interface representing a Two-Factor Authentication Secret.
 *
 * Implementations should be immutable or use value object patterns
 */
interface TwoFactorSecret
{
    /**
     * Get the user identifier associated with this secret.
     */
    public function getUserIdentifier(): string;

    /**
     * Get the secret key.
     */
    public function getSecret(): string;

    /**
     * Check if 2FA is enabled.
     */
    public function isEnabled(): bool;

    public function setEnabled(bool $enabled): void;

    public function getEnabledAt(): ?\DateTimeImmutable;

    public function setConfirmed(bool $confirmed): void;

    public function setBackupCodes(?array $backupCodes): void;

    public function setPlainBackupCodes(?array $backupCodes): void;

    /**
     * Check if 2FA is confirmed by user.
     */
    public function isConfirmed(): bool;

    public function setLastUsedAt(?\DateTimeImmutable $lastUsedAt): void;

    /**
     * Get the last time this secret was used.
     */
    public function getLastUsedAt(): ?\DateTimeImmutable;

    public function incrementFailedAttempts(): void;

    /**
     * Increment the failed attempts counter.
     */
    public function resetFailedAttempts(): void;

    /**
     * Get number of failed attempts.
     */
    public function getFailedAttempts(): int;

    /**
     * Get the TOTP time-step (counter) of the last code that was successfully
     * accepted, or null if none has been accepted yet.
     *
     * Persisting this enables replay protection: a code is accepted at most once
     * because any later code whose step is <= this value is rejected.
     */
    public function getLastUsedCounter(): ?int;

    /**
     * Store the time-step (counter) of the last accepted code.
     */
    public function setLastUsedCounter(?int $counter): void;

    /**
     * Get the instant until which further verification attempts are locked out
     * (after too many failures), or null when not locked.
     */
    public function getLockedUntil(): ?\DateTimeImmutable;

    /**
     * Set (or clear, with null) the lockout expiry.
     */
    public function setLockedUntil(?\DateTimeImmutable $lockedUntil): void;

    /**
     * Check if a backup code exists.
     */
    public function hasBackupCode(string $code): bool;

    /**
     * Get all backup codes (hashed).
     */
    public function getBackupCodes(): ?array;

    public function removeBackupCode(string $code): void;
}
