<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\RememberMe;

/**
 * Non-persistent token provider — request-scoped only. Useful for tests and
 * single-process scenarios; use FileTokenProvider (or a database-backed one)
 * in production so tokens survive across requests.
 */
final class InMemoryTokenProvider implements RememberMeTokenProviderInterface
{
    /** @var array<string, PersistentToken> */
    private array $tokens = [];

    public function loadTokenBySeries(string $series): ?PersistentToken
    {
        return $this->tokens[$series] ?? null;
    }

    public function createNewToken(PersistentToken $token): void
    {
        $this->tokens[$token->series] = $token;
    }

    public function updateExistingToken(string $series, #[\SensitiveParameter] string $tokenValue, int $lastUsed): void
    {
        $existing = $this->tokens[$series] ?? null;
        if (null === $existing) {
            return;
        }

        $this->tokens[$series] = new PersistentToken(
            userIdentifier: $existing->userIdentifier,
            series: $series,
            tokenValue: $tokenValue,
            lastUsed: $lastUsed,
        );
    }

    public function deleteTokenBySeries(string $series): void
    {
        unset($this->tokens[$series]);
    }

    public function deleteTokensByUserIdentifier(string $userIdentifier): void
    {
        foreach ($this->tokens as $series => $token) {
            if ($token->userIdentifier === $userIdentifier) {
                unset($this->tokens[$series]);
            }
        }
    }
}
