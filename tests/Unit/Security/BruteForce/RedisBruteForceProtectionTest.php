<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security\BruteForce;

use Modufolio\Appkit\Security\BruteForce\RedisBruteForceProtection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests against a real Redis server.
 *
 * The whole class is skipped when the phpredis extension is missing or no Redis
 * is reachable on 127.0.0.1:6379, so the suite still passes in environments
 * without Redis (CI without the service, contributors who only run the file
 * backend, etc.).
 */
#[CoversClass(RedisBruteForceProtection::class)]
final class RedisBruteForceProtectionTest extends TestCase
{
    private const HOST = '127.0.0.1';
    private const PORT = 6379;

    private \Redis $redis;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('phpredis extension is not installed.');
        }

        $redis = new \Redis();

        try {
            if (!@$redis->connect(self::HOST, self::PORT, 1.0) || !$redis->ping()) {
                $this->markTestSkipped('No Redis server reachable on '.self::HOST.':'.self::PORT.'.');
            }
        } catch (\RedisException $e) {
            $this->markTestSkipped('Redis connection failed: '.$e->getMessage());
        }

        // Isolate this test run in its own keyspace so we can flush safely.
        $redis->select(15);
        $redis->flushDB();

        $this->redis = $redis;
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->redis->flushDB();
            $this->redis->close();
        }
    }

    private function protection(int $maxAttempts = 3, ?int $accountMaxAttempts = null): RedisBruteForceProtection
    {
        return new RedisBruteForceProtection(
            $this->redis,
            'test_bf:',
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
        $this->assertGreaterThan(0, $bf->getRemainingLockoutTime('victim@example.com', '10.0.0.1'));
    }

    /**
     * Regression: multiple failures within the same wall-clock second must each
     * be counted. The old implementation used the timestamp itself as the sorted
     * set member, so same-second failures collapsed into one and the lockout
     * could be trivially outrun.
     */
    public function testCountsMultipleFailuresWithinTheSameSecond(): void
    {
        $bf = $this->protection(maxAttempts: 5);

        for ($i = 0; $i < 5; ++$i) {
            $bf->recordFailure('victim@example.com', '10.0.0.1');
        }

        // All 5 land in (at most) a couple of seconds; the counter must reflect
        // every attempt, not the number of distinct seconds.
        $this->assertGreaterThanOrEqual(5, $bf->getFailureCount('victim@example.com', '10.0.0.1'));
        $this->assertTrue($bf->isLocked('victim@example.com', '10.0.0.1'));
    }

    /**
     * Regression: an attacker rotating IPs previously got a fresh per-IP budget
     * against the same account and it never locked. The account-wide counter
     * must lock the account once its higher threshold is crossed.
     */
    public function testAccountWideLockoutDefeatsIpRotation(): void
    {
        // Per-IP limit 3, account-wide limit 6.
        $bf = $this->protection(maxAttempts: 3, accountMaxAttempts: 6);

        // 6 failures, each from a different IP → never trips a per-IP counter.
        for ($i = 1; $i <= 6; ++$i) {
            $bf->recordFailure('victim@example.com', '203.0.113.'.$i);
        }

        // The account is locked regardless of the source IP, including a brand
        // new one the attacker has not used yet.
        $this->assertTrue($bf->isLocked('victim@example.com', '203.0.113.250'));
        $this->assertTrue($bf->isLocked('victim@example.com', null));
    }

    public function testStaysUnlockedBelowAccountThresholdAcrossIps(): void
    {
        $bf = $this->protection(maxAttempts: 3, accountMaxAttempts: 6);

        // 5 failures across distinct IPs: under both the per-IP (3-per-IP, one
        // each) and the account-wide (6) thresholds.
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
        $this->assertSame(0, $bf->getRemainingLockoutTime('victim@example.com', '10.0.0.1'));
    }
}
