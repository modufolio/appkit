<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\BruteForce;

/**
 * Redis-based brute force protection
 *
 * This implementation uses Redis for storing failure attempts.
 * Redis provides atomic operations and automatic expiration.
 *
 * Suitable for multi-server deployments and high-traffic applications.
 * Requires the Redis PHP extension (phpredis) or Predis library.
 */
class RedisBruteForceProtection implements BruteForceProtectionInterface
{
    private \Redis $redis;
    private string $keyPrefix;
    private int $maxAttempts;
    private int $lockoutDuration; // seconds
    private int $windowDuration; // seconds - time window for counting failures

    /**
     * @param \Redis $redis Redis instance (already connected)
     * @param string $keyPrefix Key prefix for Redis keys (default: 'bruteforce:')
     * @param int $maxAttempts Maximum failed attempts before lockout (default: 5)
     * @param int $lockoutDuration Lockout duration in seconds (default: 900 = 15 minutes)
     * @param int $windowDuration Time window for counting failures in seconds (default: 300 = 5 minutes)
     */
    public function __construct(
        \Redis $redis,
        string $keyPrefix = 'bruteforce:',
        int $maxAttempts = 5,
        int $lockoutDuration = 900,
        int $windowDuration = 300
    ) {
        $this->redis = $redis;
        $this->keyPrefix = $keyPrefix;
        $this->maxAttempts = $maxAttempts;
        $this->lockoutDuration = $lockoutDuration;
        $this->windowDuration = $windowDuration;

        // Verify Redis connection
        try {
            $this->redis->ping();
        } catch (\RedisException $e) {
            throw new \RuntimeException('Redis connection failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function recordFailure(string $identifier, ?string $ipAddress = null): void
    {
        $key = $this->generateKey($identifier, $ipAddress);
        $lockKey = $this->getLockKey($key);
        $failuresKey = $this->getFailuresKey($key);

        $now = time();

        // Add failure timestamp to sorted set (score = timestamp)
        // This allows us to query by time range
        $this->redis->zAdd($failuresKey, $now, (string)$now);

        // Remove old failures outside the window
        $cutoff = $now - $this->windowDuration;
        $this->redis->zRemRangeByScore($failuresKey, '-inf', (string)$cutoff);

        // Set expiration on failures key to auto-cleanup
        $this->redis->expire($failuresKey, $this->windowDuration);

        // Count failures in the current window
        $failureCount = (int) $this->redis->zCount($failuresKey, (string)$cutoff, '+inf');

        // If we've exceeded max attempts, set lockout
        if ($failureCount >= $this->maxAttempts) {
            $this->redis->setex($lockKey, $this->lockoutDuration, (string)($now + $this->lockoutDuration));
        }
    }

    public function recordSuccess(string $identifier, ?string $ipAddress = null): void
    {
        $key = $this->generateKey($identifier, $ipAddress);
        $lockKey = $this->getLockKey($key);
        $failuresKey = $this->getFailuresKey($key);

        // Delete both the failures and lock on successful authentication
        $this->redis->del($failuresKey, $lockKey);
    }

    public function isLocked(string $identifier, ?string $ipAddress = null): bool
    {
        return $this->getRemainingLockoutTime($identifier, $ipAddress) > 0;
    }

    public function getFailureCount(string $identifier, ?string $ipAddress = null): int
    {
        $key = $this->generateKey($identifier, $ipAddress);
        $failuresKey = $this->getFailuresKey($key);

        $now = time();
        $cutoff = $now - $this->windowDuration;

        // Count failures within the window using sorted set score range
        $count = $this->redis->zCount($failuresKey, (string)$cutoff, '+inf');

        return (int)$count;
    }

    public function getRemainingLockoutTime(string $identifier, ?string $ipAddress = null): int
    {
        $key = $this->generateKey($identifier, $ipAddress);
        $lockKey = $this->getLockKey($key);

        $lockedUntil = $this->redis->get($lockKey);

        if ($lockedUntil === false) {
            return 0;
        }

        $remaining = (int)$lockedUntil - time();

        return max(0, $remaining);
    }

    public function reset(string $identifier, ?string $ipAddress = null): void
    {
        $key = $this->generateKey($identifier, $ipAddress);
        $lockKey = $this->getLockKey($key);
        $failuresKey = $this->getFailuresKey($key);

        // Delete both failures and lock
        $this->redis->del($failuresKey, $lockKey);
    }

    /**
     * Generate a safe key from identifier and IP
     */
    private function generateKey(string $identifier, ?string $ipAddress = null): string
    {
        $combined = $identifier;
        if ($ipAddress !== null) {
            $combined .= ':' . $ipAddress;
        }

        return hash('sha256', $combined);
    }

    /**
     * Get the Redis key for lockout status
     */
    private function getLockKey(string $key): string
    {
        return $this->keyPrefix . 'lock:' . $key;
    }

    /**
     * Get the Redis key for failures sorted set
     */
    private function getFailuresKey(string $key): string
    {
        return $this->keyPrefix . 'failures:' . $key;
    }

    /**
     * Factory method to create from DSN string
     *
     * Example: redis://localhost:6379/0
     * Example: redis://password@localhost:6379/1
     * Example: redis:///var/run/redis.sock
     *
     * @param string $dsn Redis connection DSN
     * @param string $keyPrefix Key prefix for Redis keys
     * @param int $maxAttempts Maximum failed attempts
     * @param int $lockoutDuration Lockout duration in seconds
     * @param int $windowDuration Window duration in seconds
     * @return self
     * @throws \RuntimeException If Redis extension is not available or connection fails
     */
    public static function fromDsn(
        string $dsn,
        string $keyPrefix = 'bruteforce:',
        int $maxAttempts = 5,
        int $lockoutDuration = 900,
        int $windowDuration = 300
    ): self {
        if (!extension_loaded('redis')) {
            throw new \RuntimeException('Redis extension (phpredis) is not installed. Install it or use FileBruteForceProtection instead.');
        }

        $redis = new \Redis();

        // Parse DSN
        $parsed = parse_url($dsn);
        if ($parsed === false) {
            throw new \RuntimeException('Invalid Redis DSN format');
        }

        $scheme = $parsed['scheme'] ?? 'redis';
        if ($scheme !== 'redis') {
            throw new \RuntimeException('Only redis:// scheme is supported');
        }

        // Unix socket connection
        if (isset($parsed['path']) && !isset($parsed['host'])) {
            $socket = $parsed['path'];
            if (!$redis->connect($socket)) {
                throw new \RuntimeException('Failed to connect to Redis via socket: ' . $socket);
            }
        } else {
            // TCP connection
            $host = $parsed['host'] ?? '127.0.0.1';
            $port = $parsed['port'] ?? 6379;
            $timeout = 2.5;

            if (!$redis->connect($host, $port, $timeout)) {
                throw new \RuntimeException(sprintf('Failed to connect to Redis at %s:%d', $host, $port));
            }
        }

        // Authenticate if password provided
        if (isset($parsed['pass']) || isset($parsed['user'])) {
            $password = $parsed['pass'] ?? null;
            if ($password !== null && !$redis->auth($password)) {
                throw new \RuntimeException('Redis authentication failed');
            }
        }

        // Select database if specified in path
        if (isset($parsed['path']) && isset($parsed['host'])) {
            $db = (int)ltrim($parsed['path'], '/');
            if (!$redis->select($db)) {
                throw new \RuntimeException('Failed to select Redis database: ' . $db);
            }
        }

        return new self($redis, $keyPrefix, $maxAttempts, $lockoutDuration, $windowDuration);
    }
}
