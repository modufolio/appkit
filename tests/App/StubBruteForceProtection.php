<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App;

use Modufolio\Appkit\Security\BruteForce\BruteForceProtectionInterface;

/**
 * Stub BruteForceProtection implementation for testing.
 * Does nothing - allows all authentication attempts.
 */
class StubBruteForceProtection implements BruteForceProtectionInterface
{
    public function isLocked(string $identifier, ?string $ipAddress = null): bool
    {
        return false;
    }

    public function recordFailure(string $identifier, ?string $ipAddress = null): void
    {
        // Do nothing in tests
    }

    public function recordSuccess(string $identifier, ?string $ipAddress = null): void
    {
        // Do nothing in tests
    }

    public function reset(string $identifier, ?string $ipAddress = null): void
    {
        // Do nothing in tests
    }

    public function getFailureCount(string $identifier, ?string $ipAddress = null): int
    {
        return 0;
    }

    public function getRemainingLockoutTime(string $identifier, ?string $ipAddress = null): int
    {
        return 0;
    }
}
