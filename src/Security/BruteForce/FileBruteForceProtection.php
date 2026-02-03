<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\BruteForce;

/**
 * File-based brute force protection with atomic file locking
 *
 * This implementation uses file system for storing failure attempts
 * with LOCK_EX (exclusive lock) for atomic read-modify-write operations.
 *
 * Suitable for single-server deployments or when Redis is not available.
 */
class FileBruteForceProtection implements BruteForceProtectionInterface
{
    private string $storageDir;
    private int $maxAttempts;
    private int $lockoutDuration; // seconds
    private int $windowDuration; // seconds - time window for counting failures

    /**
     * @param string $storageDir Directory to store failure tracking files
     * @param int $maxAttempts Maximum failed attempts before lockout (default: 5)
     * @param int $lockoutDuration Lockout duration in seconds (default: 900 = 15 minutes)
     * @param int $windowDuration Time window for counting failures in seconds (default: 300 = 5 minutes)
     */
    public function __construct(
        string $storageDir,
        int $maxAttempts = 5,
        int $lockoutDuration = 900,
        int $windowDuration = 300
    ) {
        $this->storageDir = rtrim($storageDir, '/');
        $this->maxAttempts = $maxAttempts;
        $this->lockoutDuration = $lockoutDuration;
        $this->windowDuration = $windowDuration;

        // Create storage directory if it doesn't exist
        if (!is_dir($this->storageDir)) {
            if (!mkdir($this->storageDir, 0755, true) && !is_dir($this->storageDir)) {
                throw new \RuntimeException(sprintf('Failed to create brute force storage directory: %s', $this->storageDir));
            }
        }

        if (!is_writable($this->storageDir)) {
            throw new \RuntimeException(sprintf('Brute force storage directory is not writable: %s', $this->storageDir));
        }
    }

    public function recordFailure(string $identifier, ?string $ipAddress = null): void
    {
        $key = $this->generateKey($identifier, $ipAddress);
        $data = $this->atomicRead($key);

        $now = time();
        $data['failures'][] = $now;

        // Clean up old failures outside the window
        $data['failures'] = array_filter($data['failures'], function ($timestamp) use ($now) {
            return ($now - $timestamp) <= $this->windowDuration;
        });

        // If we've exceeded max attempts, set lockout
        if (count($data['failures']) >= $this->maxAttempts) {
            $data['locked_until'] = $now + $this->lockoutDuration;
        }

        $this->atomicWrite($key, $data);
    }

    public function recordSuccess(string $identifier, ?string $ipAddress = null): void
    {
        $key = $this->generateKey($identifier, $ipAddress);
        // Reset on successful authentication
        $this->atomicWrite($key, ['failures' => [], 'locked_until' => null]);
    }

    public function isLocked(string $identifier, ?string $ipAddress = null): bool
    {
        return $this->getRemainingLockoutTime($identifier, $ipAddress) > 0;
    }

    public function getFailureCount(string $identifier, ?string $ipAddress = null): int
    {
        $key = $this->generateKey($identifier, $ipAddress);
        $data = $this->atomicRead($key);

        $now = time();

        // Filter to only count failures within the window
        $recentFailures = array_filter($data['failures'], function ($timestamp) use ($now) {
            return ($now - $timestamp) <= $this->windowDuration;
        });

        return count($recentFailures);
    }

    public function getRemainingLockoutTime(string $identifier, ?string $ipAddress = null): int
    {
        $key = $this->generateKey($identifier, $ipAddress);
        $data = $this->atomicRead($key);

        if (!isset($data['locked_until'])) {
            return 0;
        }

        $remaining = $data['locked_until'] - time();

        // If lockout has expired, clean it up
        if ($remaining <= 0) {
            $data['locked_until'] = null;
            $data['failures'] = [];
            $this->atomicWrite($key, $data);
            return 0;
        }

        return $remaining;
    }

    public function reset(string $identifier, ?string $ipAddress = null): void
    {
        $key = $this->generateKey($identifier, $ipAddress);
        $this->atomicWrite($key, ['failures' => [], 'locked_until' => null]);
    }

    /**
     * Generate a safe filename key from identifier and IP
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
     * Get the file path for a given key
     */
    private function getFilePath(string $key): string
    {
        return $this->storageDir . '/' . $key . '.json';
    }

    /**
     * Atomically read data from file with exclusive lock
     *
     * @return array{failures: array<int>, locked_until: int|null}
     */
    private function atomicRead(string $key): array
    {
        $filepath = $this->getFilePath($key);

        if (!file_exists($filepath)) {
            return ['failures' => [], 'locked_until' => null];
        }

        $handle = fopen($filepath, 'r');
        if ($handle === false) {
            return ['failures' => [], 'locked_until' => null];
        }

        try {
            // Acquire shared lock for reading
            if (flock($handle, LOCK_SH)) {
                $content = stream_get_contents($handle);
                flock($handle, LOCK_UN);

                if ($content === false || $content === '') {
                    return ['failures' => [], 'locked_until' => null];
                }

                $data = json_decode($content, true);

                if (!is_array($data)) {
                    return ['failures' => [], 'locked_until' => null];
                }

                return [
                    'failures' => $data['failures'] ?? [],
                    'locked_until' => $data['locked_until'] ?? null,
                ];
            }

            return ['failures' => [], 'locked_until' => null];
        } finally {
            fclose($handle);
        }
    }

    /**
     * Atomically write data to file with exclusive lock
     *
     * @param array{failures: array<int>, locked_until: int|null} $data
     */
    private function atomicWrite(string $key, array $data): void
    {
        $filepath = $this->getFilePath($key);

        $handle = fopen($filepath, 'c');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Failed to open file for writing: %s', $filepath));
        }

        try {
            // Acquire exclusive lock for writing (blocks until available)
            if (flock($handle, LOCK_EX)) {
                // Truncate file before writing
                ftruncate($handle, 0);
                rewind($handle);

                $json = json_encode($data, JSON_THROW_ON_ERROR);
                fwrite($handle, $json);

                // Flush to ensure data is written before releasing lock
                fflush($handle);

                flock($handle, LOCK_UN);
            } else {
                throw new \RuntimeException(sprintf('Failed to acquire lock on file: %s', $filepath));
            }
        } finally {
            fclose($handle);
        }
    }
}
