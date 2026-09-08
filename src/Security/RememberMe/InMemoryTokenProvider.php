<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\RememberMe;

/**
 * Non-persistent token provider — request-scoped only. Useful for tests and
 * single-process scenarios; use FileTokenProvider (or a database-backed one)
 * in production so tokens survive across requests.
 *
 * @author    Maarten Thiebou
 *
 * @see       https://github.com/symfony/security-core
 *
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
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

    public function updateExistingToken(PersistentToken $token, #[\SensitiveParameter] string $expectedCurrentValue): bool
    {
        $existing = $this->tokens[$token->series] ?? null;
        if (null === $existing) {
            return false;
        }

        // Single-process store: the compare and the write cannot interleave,
        // so the comparison alone satisfies the interface's atomicity contract.
        if (!hash_equals($existing->tokenValue, $expectedCurrentValue)) {
            return false;
        }

        $this->tokens[$token->series] = $token;

        return true;
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
