<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security\BruteForce;

use Modufolio\Appkit\Security\BruteForce\FileBruteForceProtection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileBruteForceProtection::class)]
final class FileBruteForceProtectionTest extends TestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir().'/bf_test_'.uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageDir)) {
            foreach (glob($this->storageDir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->storageDir);
        }
    }

    private function protection(int $maxAttempts = 3, ?int $accountMaxAttempts = null): FileBruteForceProtection
    {
        return new FileBruteForceProtection(
            $this->storageDir,
            $maxAttempts,
            lockoutDuration: 60,
            windowDuration: 300,
            accountMaxAttempts: $accountMaxAttempts,
        );
    }

    public function testLocksAfterMaxAttemptsFromSameIp(): void
    {
        $bf = $this->protection(maxAttempts: 3);

        $bf->recordFailure('victim@example.com', '10.0.0.1');
        $bf->recordFailure('victim@example.com', '10.0.0.1');
        $this->assertFalse($bf->isLocked('victim@example.com', '10.0.0.1'));

        $bf->recordFailure('victim@example.com', '10.0.0.1');
        $this->assertTrue($bf->isLocked('victim@example.com', '10.0.0.1'));
    }

    /**
     * Regression: an attacker rotating IPs previously got a fresh per-IP budget
     * against the same account and it never locked. The account-wide counter
     * must lock the account once its higher threshold is crossed.
     */
    public function testAccountWideLockoutDefeatsIpRotation(): void
    {
        $bf = $this->protection(maxAttempts: 3, accountMaxAttempts: 6);

        for ($i = 1; $i <= 6; ++$i) {
            $bf->recordFailure('victim@example.com', '203.0.113.'.$i);
        }

        $this->assertTrue($bf->isLocked('victim@example.com', '203.0.113.250'));
        $this->assertTrue($bf->isLocked('victim@example.com', null));
    }

    public function testStaysUnlockedBelowAccountThresholdAcrossIps(): void
    {
        $bf = $this->protection(maxAttempts: 3, accountMaxAttempts: 6);

        for ($i = 1; $i <= 5; ++$i) {
            $bf->recordFailure('victim@example.com', '203.0.113.'.$i);
        }

        $this->assertFalse($bf->isLocked('victim@example.com', '203.0.113.251'));
    }

    public function testRecordSuccessClearsAllCounters(): void
    {
        $bf = $this->protection(maxAttempts: 3);

        $bf->recordFailure('victim@example.com', '10.0.0.1');
        $bf->recordFailure('victim@example.com', '10.0.0.1');
        $bf->recordSuccess('victim@example.com', '10.0.0.1');

        $this->assertSame(0, $bf->getFailureCount('victim@example.com', '10.0.0.1'));
        $this->assertFalse($bf->isLocked('victim@example.com', '10.0.0.1'));
    }

    public function testResetClearsLockout(): void
    {
        $bf = $this->protection(maxAttempts: 3);

        for ($i = 0; $i < 3; ++$i) {
            $bf->recordFailure('victim@example.com', '10.0.0.1');
        }
        $this->assertTrue($bf->isLocked('victim@example.com', '10.0.0.1'));

        $bf->reset('victim@example.com', '10.0.0.1');
        $this->assertFalse($bf->isLocked('victim@example.com', '10.0.0.1'));
    }
}
