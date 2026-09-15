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

    /**
     * Files whose last write predates both the window and the lockout can
     * hold nothing live; prune() removes exactly those.
     */
    public function testPruneRemovesOnlyFilesThatCanHoldNothingLive(): void
    {
        $bf = $this->protection();
        $bf->recordFailure('stale@example.com', '10.0.0.1');
        $bf->recordFailure('fresh@example.com', '10.0.0.2');

        $files = glob($this->storageDir.'/*.json') ?: [];
        $this->assertCount(4, $files, 'two counters per failure');

        // Age the stale account's two files past max(window, lockout) = 300s.
        // The hashed names are opaque, so age every file, then let a new
        // failure re-touch the fresh account's pair.
        foreach ($files as $file) {
            touch($file, time() - 301);
        }
        $bf->recordFailure('fresh@example.com', '10.0.0.2');

        $removed = $bf->prune();

        $this->assertSame(2, $removed);
        $this->assertCount(2, glob($this->storageDir.'/*.json') ?: []);
        $this->assertSame(2, $bf->getFailureCount('fresh@example.com', '10.0.0.2'));
        $this->assertSame(0, $bf->getFailureCount('stale@example.com', '10.0.0.1'));
    }

    /**
     * A counter file that exists but cannot be opened is not "no failures":
     * the read fails closed like the write path, so a locked account cannot
     * slip through on a permissions or descriptor problem.
     */
    public function testUnreadableStateFailsClosed(): void
    {
        if (0 === (function_exists('posix_geteuid') ? posix_geteuid() : 1)) {
            $this->markTestSkipped('root ignores file permissions.');
        }

        $bf = $this->protection(maxAttempts: 1);
        $bf->recordFailure('locked@example.com', '10.0.0.1');
        $this->assertTrue($bf->isLocked('locked@example.com', '10.0.0.1'));

        foreach (glob($this->storageDir.'/*.json') ?: [] as $file) {
            chmod($file, 0o000);
        }

        try {
            $this->expectException(\RuntimeException::class);
            @$bf->isLocked('locked@example.com', '10.0.0.1');
        } finally {
            foreach (glob($this->storageDir.'/*.json') ?: [] as $file) {
                chmod($file, 0o644);
            }
        }
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

    /**
     * The account identifier is case-insensitive: failures recorded under
     * 'Ryan' and 'ryan' accumulate into the same counter, so an attacker
     * can't reset the throttle by varying the case of the username/email.
     */
    public function testIdentifierIsCaseInsensitive(): void
    {
        $bf = $this->protection(maxAttempts: 3);

        $bf->recordFailure('Ryan', '10.0.0.1');
        $bf->recordFailure('ryan', '10.0.0.1');
        $this->assertFalse($bf->isLocked('RYAN', '10.0.0.1'));

        // Third attempt in yet another case crosses the threshold on the shared
        // counter and the account is locked regardless of the case queried.
        $bf->recordFailure('rYaN', '10.0.0.1');
        $this->assertTrue($bf->isLocked('RYAN', '10.0.0.1'));
        $this->assertTrue($bf->isLocked('ryan', '10.0.0.1'));
        $this->assertSame(3, $bf->getFailureCount('Ryan', '10.0.0.1'));
    }

    /**
     * Normalization must not leak across the IP dimension: the same identifier
     * from a different IP still gets its own per-IP counter.
     */
    public function testIdentifierNormalizationKeepsIpSeparation(): void
    {
        $bf = $this->protection(maxAttempts: 3, accountMaxAttempts: 100);

        $bf->recordFailure('Ryan', '10.0.0.1');
        $bf->recordFailure('ryan', '10.0.0.1');
        $bf->recordFailure('RYAN', '10.0.0.1');

        $this->assertTrue($bf->isLocked('ryan', '10.0.0.1'));
        $this->assertFalse($bf->isLocked('ryan', '10.0.0.2'));
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
