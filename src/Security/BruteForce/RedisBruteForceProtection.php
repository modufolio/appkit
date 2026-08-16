<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\BruteForce;

/**
 * Redis-based brute force protection.
 *
 * This implementation uses Redis for storing failure attempts.
 * Redis provides atomic operations and automatic expiration.
 *
 * Suitable for multi-server deployments and high-traffic applications.
 * Requires the Redis PHP extension (phpredis) or Predis library.
 */
class RedisBruteForceProtection implements BruteForceProtectionInterface
{
    /**
     * Atomic record-failure: append a unique failure marker, prune the window,
     * refresh the TTL, count, and lock if over threshold — all in one round trip
     * so concurrent requests can't each read a sub-threshold count and slip past.
     *
     * KEYS[1] failures sorted set, KEYS[2] lock key.
     * ARGV: 1 now, 2 unique member, 3 cutoff, 4 windowDuration, 5 maxAttempts,
     *       6 lockoutDuration, 7 lockValue.
     */
    private const RECORD_FAILURE_LUA = <<<'LUA'
        redis.call('ZADD', KEYS[1], ARGV[1], ARGV[2])
        redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', ARGV[3])
        redis.call('EXPIRE', KEYS[1], ARGV[4])
        local count = redis.call('ZCOUNT', KEYS[1], ARGV[3], '+inf')
        if count >= tonumber(ARGV[5]) then
            redis.call('SETEX', KEYS[2], ARGV[6], ARGV[7])
        end
        return count
        LUA;

    private \Redis $redis;
    private string $keyPrefix;
    private int $maxAttempts;
    private int $accountMaxAttempts;
    private int $lockoutDuration; // seconds
    private int $windowDuration; // seconds - time window for counting failures

    /**
     * @param \Redis   $redis              Redis instance (already connected)
     * @param string   $keyPrefix          Key prefix for Redis keys (default: 'bruteforce:')
     * @param int      $maxAttempts        Max failed attempts per (account + IP) before lockout (default: 5)
     * @param int      $lockoutDuration    Lockout duration in seconds (default: 900 = 15 minutes)
     * @param int      $windowDuration     Time window for counting failures in seconds (default: 300 = 5 minutes)
     * @param int|null $accountMaxAttempts Max failed attempts per account across ALL IPs before the account
     *                                     locks. Defends against distributed / IP-rotating guessing that the
     *                                     per-IP counter alone cannot see. Higher than $maxAttempts so a single
     *                                     source can't trivially lock a victim out. Null defaults to 5 * $maxAttempts.
     */
    public function __construct(
        \Redis $redis,
        string $keyPrefix = 'bruteforce:',
        int $maxAttempts = 5,
        int $lockoutDuration = 900,
        int $windowDuration = 300,
        ?int $accountMaxAttempts = null,
    ) {
        $this->redis = $redis;
        $this->keyPrefix = $keyPrefix;
        $this->maxAttempts = $maxAttempts;
        $this->accountMaxAttempts = $accountMaxAttempts ?? (5 * $maxAttempts);
        $this->lockoutDuration = $lockoutDuration;
        $this->windowDuration = $windowDuration;

        // Verify Redis connection
        try {
            $this->redis->ping();
        } catch (\RedisException $e) {
            throw new \RuntimeException('Redis connection failed: '.$e->getMessage(), 0, $e);
        }
    }

    public function recordFailure(string $identifier, ?string $ipAddress = null): void
    {
        $now = time();
        $cutoff = $now - $this->windowDuration;

        foreach ($this->counters($identifier, $ipAddress) as [$key, $threshold]) {
            // Unique member so multiple failures in the same second are all
            // counted (a bare timestamp member would collapse them into one).
            $member = $now.'-'.bin2hex(random_bytes(8));

            $this->redis->eval(
                self::RECORD_FAILURE_LUA,
                [
                    $this->getFailuresKey($key),
                    $this->getLockKey($key),
                    (string) $now,
                    $member,
                    (string) $cutoff,
                    (string) $this->windowDuration,
                    (string) $threshold,
                    (string) $this->lockoutDuration,
                    (string) ($now + $this->lockoutDuration),
                ],
                2,
            );
        }
    }

    public function recordSuccess(string $identifier, ?string $ipAddress = null): void
    {
        foreach ($this->counters($identifier, $ipAddress) as [$key]) {
            $this->redis->del($this->getFailuresKey($key), $this->getLockKey($key));
        }
    }

    public function isLocked(string $identifier, ?string $ipAddress = null): bool
    {
        return $this->getRemainingLockoutTime($identifier, $ipAddress) > 0;
    }

    public function getFailureCount(string $identifier, ?string $ipAddress = null): int
    {
        $now = time();
        $cutoff = $now - $this->windowDuration;

        $max = 0;
        foreach ($this->counters($identifier, $ipAddress) as [$key]) {
            $count = (int) $this->redis->zCount($this->getFailuresKey($key), (string) $cutoff, '+inf');
            $max = max($max, $count);
        }

        return $max;
    }

    public function getRemainingLockoutTime(string $identifier, ?string $ipAddress = null): int
    {
        $now = time();

        $remaining = 0;
        foreach ($this->counters($identifier, $ipAddress) as [$key]) {
            $lockedUntil = $this->redis->get($this->getLockKey($key));
            if (false !== $lockedUntil) {
                $remaining = max($remaining, (int) $lockedUntil - $now);
            }
        }

        return max(0, $remaining);
    }

    public function reset(string $identifier, ?string $ipAddress = null): void
    {
        foreach ($this->counters($identifier, $ipAddress) as [$key]) {
            $this->redis->del($this->getFailuresKey($key), $this->getLockKey($key));
        }
    }

    /**
     * The set of independent counters a failure/check fans out to.
     *
     * Each entry is [hashedKey, threshold]:
     *  - the account-wide counter (IP-independent) at $accountMaxAttempts;
     *  - the tighter per-(account + IP) counter at $maxAttempts, when an IP is known.
     *
     * @return list<array{string, int}>
     */
    private function counters(string $identifier, ?string $ipAddress): array
    {
        $counters = [[$this->generateKey($identifier, null), $this->accountMaxAttempts]];

        if (null !== $ipAddress) {
            $counters[] = [$this->generateKey($identifier, $ipAddress), $this->maxAttempts];
        }

        return $counters;
    }

    /**
     * Generate a safe key from identifier and IP.
     */
    private function generateKey(string $identifier, ?string $ipAddress = null): string
    {
        // Normalize the account identifier so the same username/email in different
        // case (Ryan / ryan / rYan) maps to a single counter and can't be
        // used to bypass throttling. The IP is left untouched.
        $combined = mb_strtolower($identifier);
        if (null !== $ipAddress) {
            $combined .= ':'.$ipAddress;
        }

        return hash('sha256', $combined);
    }

    /**
     * Get the Redis key for lockout status.
     */
    private function getLockKey(string $key): string
    {
        return $this->keyPrefix.'lock:'.$key;
    }

    /**
     * Get the Redis key for failures sorted set.
     */
    private function getFailuresKey(string $key): string
    {
        return $this->keyPrefix.'failures:'.$key;
    }

    /**
     * Factory method to create from DSN string.
     *
     * Example: redis://localhost:6379/0
     * Example: redis://password@localhost:6379/1
     * Example: redis:///var/run/redis.sock
     *
     * @param string   $dsn                Redis connection DSN
     * @param string   $keyPrefix          Key prefix for Redis keys
     * @param int      $maxAttempts        Maximum failed attempts
     * @param int      $lockoutDuration    Lockout duration in seconds
     * @param int      $windowDuration     Window duration in seconds
     * @param int|null $accountMaxAttempts Max failed attempts per account across all IPs; null defaults to 5 * $maxAttempts
     *
     * @throws \RuntimeException If Redis extension is not available or connection fails
     */
    public static function fromDsn(
        string $dsn,
        string $keyPrefix = 'bruteforce:',
        int $maxAttempts = 5,
        int $lockoutDuration = 900,
        int $windowDuration = 300,
        ?int $accountMaxAttempts = null,
    ): self {
        if (!extension_loaded('redis')) {
            throw new \RuntimeException('Redis extension (phpredis) is not installed. Install it or use FileBruteForceProtection instead.');
        }

        $redis = new \Redis();

        // Parse DSN
        $parsed = parse_url($dsn);
        if (false === $parsed) {
            throw new \RuntimeException('Invalid Redis DSN format');
        }

        $scheme = $parsed['scheme'] ?? 'redis';
        if ('redis' !== $scheme) {
            throw new \RuntimeException('Only redis:// scheme is supported');
        }

        // Unix socket connection
        if (isset($parsed['path']) && !isset($parsed['host'])) {
            $socket = $parsed['path'];
            if (!$redis->connect($socket)) {
                throw new \RuntimeException('Failed to connect to Redis via socket: '.$socket);
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
            if (null !== $password && !$redis->auth($password)) {
                throw new \RuntimeException('Redis authentication failed');
            }
        }

        // Select database if specified in path
        if (isset($parsed['path'], $parsed['host'])) {
            $db = (int) ltrim($parsed['path'], '/');
            if (!$redis->select($db)) {
                throw new \RuntimeException('Failed to select Redis database: '.$db);
            }
        }

        return new self($redis, $keyPrefix, $maxAttempts, $lockoutDuration, $windowDuration, $accountMaxAttempts);
    }
}
