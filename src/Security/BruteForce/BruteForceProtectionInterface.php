<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\BruteForce;

/**
 * Interface for brute force protection implementations
 *
 * Provides rate limiting and account lockout functionality to prevent
 * brute force authentication attacks.
 */
interface BruteForceProtectionInterface
{
    /**
     * Record a failed authentication attempt for the given identifier
     *
     * @param string $identifier User identifier (username, email, IP, etc.)
     * @param string|null $ipAddress Optional IP address for additional tracking
     * @return void
     */
    public function recordFailure(string $identifier, ?string $ipAddress = null): void;

    /**
     * Record a successful authentication to reset failure counter
     *
     * @param string $identifier User identifier (username, email, IP, etc.)
     * @param string|null $ipAddress Optional IP address for additional tracking
     * @return void
     */
    public function recordSuccess(string $identifier, ?string $ipAddress = null): void;

    /**
     * Check if the identifier is currently locked out due to too many failures
     *
     * @param string $identifier User identifier (username, email, IP, etc.)
     * @param string|null $ipAddress Optional IP address for additional checking
     * @return bool True if locked out, false otherwise
     */
    public function isLocked(string $identifier, ?string $ipAddress = null): bool;

    /**
     * Get the number of failed attempts for the given identifier
     *
     * @param string $identifier User identifier (username, email, IP, etc.)
     * @param string|null $ipAddress Optional IP address
     * @return int Number of failed attempts
     */
    public function getFailureCount(string $identifier, ?string $ipAddress = null): int;

    /**
     * Get the remaining lockout time in seconds (0 if not locked)
     *
     * @param string $identifier User identifier (username, email, IP, etc.)
     * @param string|null $ipAddress Optional IP address
     * @return int Seconds remaining in lockout period
     */
    public function getRemainingLockoutTime(string $identifier, ?string $ipAddress = null): int;

    /**
     * Manually reset/clear all failures for an identifier
     *
     * @param string $identifier User identifier (username, email, IP, etc.)
     * @param string|null $ipAddress Optional IP address
     * @return void
     */
    public function reset(string $identifier, ?string $ipAddress = null): void;
}
