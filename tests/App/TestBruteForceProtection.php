<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App;

use Modufolio\Appkit\Security\BruteForce\BruteForceProtectionInterface;

/**
 * Configurable brute-force protection for testing.
 *
 * Tracks failures/successes in memory and can be configured
 * to simulate lockout behaviour.
 */
class TestBruteForceProtection implements BruteForceProtectionInterface
{
    /** @var array<string, int> */
    private array $failures = [];

    private int $maxAttempts;
    private int $lockoutSeconds;

    /** @var array<string, int> timestamps of when lockout started */
    private array $lockedAt = [];

    public function __construct(int $maxAttempts = 5, int $lockoutSeconds = 300)
    {
        $this->maxAttempts = $maxAttempts;
        $this->lockoutSeconds = $lockoutSeconds;
    }

    public function recordFailure(string $identifier, ?string $ipAddress = null): void
    {
        $key = $this->key($identifier, $ipAddress);
        $this->failures[$key] = ($this->failures[$key] ?? 0) + 1;

        if ($this->failures[$key] >= $this->maxAttempts) {
            $this->lockedAt[$key] = time();
        }
    }

    public function recordSuccess(string $identifier, ?string $ipAddress = null): void
    {
        $key = $this->key($identifier, $ipAddress);
        unset($this->failures[$key], $this->lockedAt[$key]);
    }

    public function isLocked(string $identifier, ?string $ipAddress = null): bool
    {
        $key = $this->key($identifier, $ipAddress);

        if (!isset($this->lockedAt[$key])) {
            return false;
        }

        if (time() - $this->lockedAt[$key] >= $this->lockoutSeconds) {
            unset($this->lockedAt[$key], $this->failures[$key]);
            return false;
        }

        return true;
    }

    public function getFailureCount(string $identifier, ?string $ipAddress = null): int
    {
        return $this->failures[$this->key($identifier, $ipAddress)] ?? 0;
    }

    public function getRemainingLockoutTime(string $identifier, ?string $ipAddress = null): int
    {
        $key = $this->key($identifier, $ipAddress);

        if (!isset($this->lockedAt[$key])) {
            return 0;
        }

        return max(0, $this->lockoutSeconds - (time() - $this->lockedAt[$key]));
    }

    public function reset(string $identifier, ?string $ipAddress = null): void
    {
        $key = $this->key($identifier, $ipAddress);
        unset($this->failures[$key], $this->lockedAt[$key]);
    }

    /**
     * Force a lockout for testing purposes.
     */
    public function forceLock(string $identifier, ?string $ipAddress = null): void
    {
        $key = $this->key($identifier, $ipAddress);
        $this->failures[$key] = $this->maxAttempts;
        $this->lockedAt[$key] = time();
    }

    private function key(string $identifier, ?string $ipAddress): string
    {
        return $identifier . ':' . ($ipAddress ?? '');
    }
}
