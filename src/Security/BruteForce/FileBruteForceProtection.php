<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\BruteForce;

/**
 * File-based brute force protection with atomic file locking.
 *
 * Read-modify-write cycles are performed under a single LOCK_EX, so concurrent
 * recordFailure() calls under load do not lose increments.
 */
class FileBruteForceProtection implements BruteForceProtectionInterface
{
    private string $storageDir;
    private int $maxAttempts;
    private int $accountMaxAttempts;
    private int $lockoutDuration;
    private int $windowDuration;

    /**
     * @param int      $maxAttempts        Max failed attempts per (account + IP) before lockout
     * @param int|null $accountMaxAttempts Max failed attempts per account across ALL IPs before the account
     *                                     locks. Defends against distributed / IP-rotating guessing that the
     *                                     per-IP counter alone cannot see. Higher than $maxAttempts so a single
     *                                     source can't trivially lock a victim out. Null defaults to 5 * $maxAttempts.
     */
    public function __construct(
        string $storageDir,
        int $maxAttempts = 5,
        int $lockoutDuration = 900,
        int $windowDuration = 300,
        ?int $accountMaxAttempts = null,
    ) {
        $this->storageDir = rtrim($storageDir, '/');
        $this->maxAttempts = $maxAttempts;
        $this->accountMaxAttempts = $accountMaxAttempts ?? (5 * $maxAttempts);
        $this->lockoutDuration = $lockoutDuration;
        $this->windowDuration = $windowDuration;

        if (!is_dir($this->storageDir)) {
            if (!mkdir($this->storageDir, 0o755, true) && !is_dir($this->storageDir)) {
                throw new \RuntimeException(sprintf('Failed to create brute force storage directory: %s', $this->storageDir));
            }
        }

        if (!is_writable($this->storageDir)) {
            throw new \RuntimeException(sprintf('Brute force storage directory is not writable: %s', $this->storageDir));
        }
    }

    public function recordFailure(string $identifier, ?string $ipAddress = null): void
    {
        foreach ($this->counters($identifier, $ipAddress) as [$key, $threshold]) {
            $this->modify($key, function (array $data, int $now) use ($threshold): array {
                $data['failures'][] = $now;
                $data['failures'] = array_values(array_filter(
                    $data['failures'],
                    fn ($timestamp): bool => ($now - $timestamp) <= $this->windowDuration,
                ));

                if (count($data['failures']) >= $threshold) {
                    $data['locked_until'] = $now + $this->lockoutDuration;
                }

                return $data;
            });
        }
    }

    public function recordSuccess(string $identifier, ?string $ipAddress = null): void
    {
        foreach ($this->counters($identifier, $ipAddress) as [$key]) {
            $this->modify($key, static fn () => ['failures' => [], 'locked_until' => null]);
        }
    }

    public function isLocked(string $identifier, ?string $ipAddress = null): bool
    {
        return $this->getRemainingLockoutTime($identifier, $ipAddress) > 0;
    }

    public function getFailureCount(string $identifier, ?string $ipAddress = null): int
    {
        $now = time();

        $max = 0;
        foreach ($this->counters($identifier, $ipAddress) as [$key]) {
            $data = $this->read($key);
            $count = count(array_filter(
                $data['failures'],
                fn ($timestamp): bool => ($now - $timestamp) <= $this->windowDuration,
            ));
            $max = max($max, $count);
        }

        return $max;
    }

    public function getRemainingLockoutTime(string $identifier, ?string $ipAddress = null): int
    {
        $remaining = 0;

        foreach ($this->counters($identifier, $ipAddress) as [$key]) {
            // Quick lock-free check; if not locked, no write needed.
            $data = $this->read($key);
            if (!isset($data['locked_until'])) {
                continue;
            }

            if ($data['locked_until'] - time() > 0) {
                $remaining = max($remaining, $data['locked_until'] - time());

                continue;
            }

            // Lockout expired — clear under an exclusive lock.
            $this->modify($key, static function (array $data, int $now): array {
                if (isset($data['locked_until']) && $data['locked_until'] <= $now) {
                    return ['failures' => [], 'locked_until' => null];
                }

                return $data;
            });
        }

        return $remaining;
    }

    public function reset(string $identifier, ?string $ipAddress = null): void
    {
        foreach ($this->counters($identifier, $ipAddress) as [$key]) {
            $this->modify($key, static fn () => ['failures' => [], 'locked_until' => null]);
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

    private function generateKey(string $identifier, ?string $ipAddress = null): string
    {
        return hash('sha256', $identifier.(null !== $ipAddress ? ':'.$ipAddress : ''));
    }

    private function getFilePath(string $key): string
    {
        return $this->storageDir.'/'.$key.'.json';
    }

    /**
     * Read state under a shared lock.
     *
     * @return array{failures: list<int>, locked_until: int|null}
     */
    private function read(string $key): array
    {
        $filepath = $this->getFilePath($key);
        if (!file_exists($filepath)) {
            return ['failures' => [], 'locked_until' => null];
        }

        $handle = fopen($filepath, 'r');
        if (false === $handle) {
            return ['failures' => [], 'locked_until' => null];
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                return ['failures' => [], 'locked_until' => null];
            }
            $content = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return $this->decode($content);
    }

    /**
     * Read-modify-write under a single exclusive lock.
     *
     * @param string                                                                                                                $key     Pre-hashed storage key
     * @param callable(array{failures: list<int>, locked_until: int|null}, int): array{failures: list<int>, locked_until: int|null} $mutator
     */
    private function modify(string $key, callable $mutator): void
    {
        $filepath = $this->getFilePath($key);

        $handle = fopen($filepath, 'c+');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('Failed to open file for writing: %s', $filepath));
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException(sprintf('Failed to acquire lock on file: %s', $filepath));
            }

            rewind($handle);
            $content = stream_get_contents($handle);
            $data = $this->decode($content);

            $data = $mutator($data, time());

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($data, JSON_THROW_ON_ERROR));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{failures: list<int>, locked_until: int|null}
     */
    private function decode(string|false $content): array
    {
        if (false === $content || '' === $content) {
            return ['failures' => [], 'locked_until' => null];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return ['failures' => [], 'locked_until' => null];
        }

        return [
            'failures' => array_values(array_filter(
                is_array($data['failures'] ?? null) ? $data['failures'] : [],
                'is_int',
            )),
            'locked_until' => isset($data['locked_until']) && is_int($data['locked_until']) ? $data['locked_until'] : null,
        ];
    }
}
