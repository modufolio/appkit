<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\RememberMe;

/**
 * File-based remember-me token store: one JSON file per series.
 *
 * The default persistent backend, mirroring FileBruteForceProtection — fine for
 * a single host; use a database-backed provider for multi-host deployments.
 * The on-disk file holds the value HASH, never the raw cookie value.
 *
 * Rotation runs as a compare-and-swap under a held `LOCK_EX`, so two requests
 * arriving together cannot interleave their writes — see
 * {@see RememberMeTokenProviderInterface::updateExistingToken()}.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class FileTokenProvider implements RememberMeTokenProviderInterface
{
    private string $storageDir;

    public function __construct(string $storageDir)
    {
        $this->storageDir = rtrim($storageDir, '/');

        if (!is_dir($this->storageDir)) {
            if (!mkdir($this->storageDir, 0o755, true) && !is_dir($this->storageDir)) {
                throw new \RuntimeException(sprintf('Failed to create remember-me token directory: %s', $this->storageDir));
            }
        }

        if (!is_writable($this->storageDir)) {
            throw new \RuntimeException(sprintf('Remember-me token directory is not writable: %s', $this->storageDir));
        }
    }

    public function loadTokenBySeries(string $series): ?PersistentToken
    {
        $file = $this->file($series);
        if (!is_file($file)) {
            return null;
        }

        $raw = file_get_contents($file);
        if (false === $raw) {
            return null;
        }

        return $this->decode($series, $raw);
    }

    public function createNewToken(PersistentToken $token): void
    {
        file_put_contents($this->file($token->series), $this->encode($token), LOCK_EX);
    }

    public function updateExistingToken(PersistentToken $token, #[\SensitiveParameter] string $expectedCurrentValue): bool
    {
        $handle = @fopen($this->file($token->series), 'r+');
        if (false === $handle) {
            return false;
        }

        try {
            // Held across the read, the comparison and the write: a second
            // request rotating the same series blocks here and then finds the
            // value already moved on, so exactly one rotation lands.
            if (!flock($handle, LOCK_EX)) {
                return false;
            }

            $raw = stream_get_contents($handle);
            if (false === $raw) {
                return false;
            }

            $stored = $this->decode($token->series, $raw);
            if (null === $stored || !hash_equals($stored->tokenValue, $expectedCurrentValue)) {
                return false;
            }

            $payload = $this->encode($token);

            rewind($handle);
            if (!ftruncate($handle, 0)) {
                return false;
            }
            if (false === fwrite($handle, $payload)) {
                return false;
            }
            fflush($handle);

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function deleteTokenBySeries(string $series): void
    {
        $file = $this->file($series);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public function deleteTokensByUserIdentifier(string $userIdentifier): void
    {
        foreach (glob($this->storageDir.'/*.json') ?: [] as $file) {
            $raw = file_get_contents($file);
            if (false === $raw) {
                continue;
            }
            $data = json_decode($raw, true);
            if (is_array($data) && ($data['userIdentifier'] ?? null) === $userIdentifier) {
                @unlink($file);
            }
        }
    }

    private function encode(PersistentToken $token): string
    {
        return json_encode([
            'userIdentifier' => $token->userIdentifier,
            'tokenValue' => $token->tokenValue,
            'lastUsed' => $token->lastUsed,
            'previousTokenValue' => $token->previousTokenValue,
            'previousValueExpiresAt' => $token->previousValueExpiresAt,
        ], JSON_THROW_ON_ERROR);
    }

    private function decode(string $series, string $raw): ?PersistentToken
    {
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['userIdentifier'], $data['tokenValue'], $data['lastUsed'])) {
            return null;
        }

        $previous = $data['previousTokenValue'] ?? null;

        return new PersistentToken(
            userIdentifier: (string) $data['userIdentifier'],
            series: $series,
            tokenValue: (string) $data['tokenValue'],
            lastUsed: (int) $data['lastUsed'],
            // Records written before rotation carried a previous value simply
            // have none — they read back as a token that has never rotated.
            previousTokenValue: is_string($previous) ? $previous : null,
            previousValueExpiresAt: (int) ($data['previousValueExpiresAt'] ?? 0),
        );
    }

    /**
     * Series are high-entropy random strings; hashing yields a fixed, safe
     * filename and never collides in practice.
     */
    private function file(string $series): string
    {
        return $this->storageDir.'/'.hash('sha256', $series).'.json';
    }
}
