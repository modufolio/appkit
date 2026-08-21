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

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['userIdentifier'], $data['tokenValue'], $data['lastUsed'])) {
            return null;
        }

        return new PersistentToken(
            userIdentifier: (string) $data['userIdentifier'],
            series: $series,
            tokenValue: (string) $data['tokenValue'],
            lastUsed: (int) $data['lastUsed'],
        );
    }

    public function createNewToken(PersistentToken $token): void
    {
        $this->write($token);
    }

    public function updateExistingToken(string $series, #[\SensitiveParameter] string $tokenValue, int $lastUsed): void
    {
        $existing = $this->loadTokenBySeries($series);
        if (null === $existing) {
            return;
        }

        $this->write(new PersistentToken(
            userIdentifier: $existing->userIdentifier,
            series: $series,
            tokenValue: $tokenValue,
            lastUsed: $lastUsed,
        ));
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

    private function write(PersistentToken $token): void
    {
        $payload = json_encode([
            'userIdentifier' => $token->userIdentifier,
            'tokenValue' => $token->tokenValue,
            'lastUsed' => $token->lastUsed,
        ], JSON_THROW_ON_ERROR);

        file_put_contents($this->file($token->series), $payload, LOCK_EX);
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
