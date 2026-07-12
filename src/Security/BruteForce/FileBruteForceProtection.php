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
    private int $lockoutDuration;
    private int $windowDuration;

    public function __construct(
        string $storageDir,
        int $maxAttempts = 5,
        int $lockoutDuration = 900,
        int $windowDuration = 300,
    ) {
        $this->storageDir = rtrim($storageDir, '/');
        $this->maxAttempts = $maxAttempts;
        $this->lockoutDuration = $lockoutDuration;
        $this->windowDuration = $windowDuration;

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
        $this->modify($identifier, $ipAddress, function (array $data, int $now): array {
            $data['failures'][] = $now;
            $data['failures'] = array_values(array_filter(
                $data['failures'],
                fn ($timestamp): bool => ($now - $timestamp) <= $this->windowDuration,
            ));

            if (count($data['failures']) >= $this->maxAttempts) {
                $data['locked_until'] = $now + $this->lockoutDuration;
            }

            return $data;
        });
    }

    public function recordSuccess(string $identifier, ?string $ipAddress = null): void
    {
        $this->modify($identifier, $ipAddress, fn () => ['failures' => [], 'locked_until' => null]);
    }

    public function isLocked(string $identifier, ?string $ipAddress = null): bool
    {
        return $this->getRemainingLockoutTime($identifier, $ipAddress) > 0;
    }

    public function getFailureCount(string $identifier, ?string $ipAddress = null): int
    {
        $data = $this->read($this->generateKey($identifier, $ipAddress));
        $now = time();

        return count(array_filter(
            $data['failures'],
            fn ($timestamp): bool => ($now - $timestamp) <= $this->windowDuration,
        ));
    }

    public function getRemainingLockoutTime(string $identifier, ?string $ipAddress = null): int
    {
        // Quick lock-free check; if not locked, no write needed.
        $data = $this->read($this->generateKey($identifier, $ipAddress));
        if (!isset($data['locked_until'])) {
            return 0;
        }

        $remaining = $data['locked_until'] - time();
        if ($remaining > 0) {
            return $remaining;
        }

        // Lockout expired — clear under an exclusive lock.
        $this->modify($identifier, $ipAddress, function (array $data, int $now): array {
            if (isset($data['locked_until']) && $data['locked_until'] <= $now) {
                return ['failures' => [], 'locked_until' => null];
            }

            return $data;
        });

        return 0;
    }

    public function reset(string $identifier, ?string $ipAddress = null): void
    {
        $this->modify($identifier, $ipAddress, fn () => ['failures' => [], 'locked_until' => null]);
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
     * @param callable(array{failures: list<int>, locked_until: int|null}, int): array{failures: list<int>, locked_until: int|null} $mutator
     */
    private function modify(string $identifier, ?string $ipAddress, callable $mutator): void
    {
        $filepath = $this->getFilePath($this->generateKey($identifier, $ipAddress));

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
