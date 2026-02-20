<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\TwoFactor;

use Modufolio\Appkit\Exception\AppkitException;

/**
 * Exception for Two-Factor Authentication errors
 *
 * Wraps 2FA-specific errors for proper exception handling
 */
class TwoFactorException extends AppkitException
{
    /**
     * Too many failed 2FA attempts
     */
    public static function tooManyFailedAttempts(int $attempts, int $maxAttempts): self
    {
        return new self(
            sprintf(
                '2FA verification failed. Too many attempts (%d/%d). Please try again later.',
                $attempts,
                $maxAttempts
            )
        );
    }

    /**
     * 2FA is already enabled for the user
     */
    public static function alreadyEnabled(): self
    {
        return new self('Two-factor authentication is already enabled for this user.');
    }

    /**
     * 2FA is not enabled for the user
     */
    public static function notEnabled(): self
    {
        return new self('Two-factor authentication is not enabled for this user.');
    }

    /**
     * Invalid 2FA code
     */
    public static function invalidCode(): self
    {
        return new self('Invalid two-factor authentication code.');
    }

    /**
     * Invalid backup code
     */
    public static function invalidBackupCode(): self
    {
        return new self('Invalid backup code.');
    }

    /**
     * 2FA secret not found
     */
    public static function secretNotFound(): self
    {
        return new self('Two-factor authentication secret not found.');
    }

    /**
     * Generic 2FA error
     */
    public static function error(string $message): self
    {
        return new self($message);
    }
}
