<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App;

use Modufolio\Appkit\Security\Csrf\CsrfToken;
use Modufolio\Appkit\Security\Csrf\CsrfTokenManagerInterface;

/**
 * In-memory CSRF token manager for testing.
 *
 * By default accepts all tokens. Call rejectAll() to reject all tokens.
 */
class TestCsrfTokenManager implements CsrfTokenManagerInterface
{
    /** @var array<string, string> tokenId => value */
    private array $tokens = [];

    private bool $acceptAll;

    public function __construct(bool $acceptAll = true)
    {
        $this->acceptAll = $acceptAll;
    }

    public function getToken(?string $tokenId = null): CsrfToken
    {
        $tokenId ??= 'default';

        if (!isset($this->tokens[$tokenId])) {
            $this->tokens[$tokenId] = bin2hex(random_bytes(16));
        }

        return new CsrfToken($tokenId, $this->tokens[$tokenId]);
    }

    public function refreshToken(?string $tokenId = null): CsrfToken
    {
        $tokenId ??= 'default';
        $this->tokens[$tokenId] = bin2hex(random_bytes(16));

        return new CsrfToken($tokenId, $this->tokens[$tokenId]);
    }

    public function isTokenValid(CsrfToken $token): bool
    {
        if ($this->acceptAll) {
            return true;
        }

        return isset($this->tokens[$token->getId()])
            && hash_equals($this->tokens[$token->getId()], $token->getValue());
    }

    public function validateToken(string $tokenId, string $tokenValue): bool
    {
        if ($this->acceptAll) {
            return true;
        }

        return isset($this->tokens[$tokenId])
            && hash_equals($this->tokens[$tokenId], $tokenValue);
    }

    public function removeToken(?string $tokenId = null): void
    {
        unset($this->tokens[$tokenId ?? 'default']);
    }

    public function hasToken(?string $tokenId = null): bool
    {
        return isset($this->tokens[$tokenId ?? 'default']);
    }

    public function clear(): void
    {
        $this->tokens = [];
    }

    /**
     * Configure the manager to reject all token validations.
     */
    public function rejectAll(): void
    {
        $this->acceptAll = false;
    }

    /**
     * Configure the manager to accept all token validations.
     */
    public function acceptAll(): void
    {
        $this->acceptAll = true;
    }
}
