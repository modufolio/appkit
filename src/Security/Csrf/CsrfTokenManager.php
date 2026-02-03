<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Csrf;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * CSRF Token Manager
 *
 * Generates, stores, and validates CSRF tokens to prevent Cross-Site Request Forgery attacks.
 * Tokens are stored in session and validated against submitted values using timing-safe comparison.
 *
 * Based on Symfony Security CSRF component best practices.
 */
class CsrfTokenManager implements CsrfTokenManagerInterface
{
    private const SESSION_KEY = '_csrf_tokens';
    private const TOKEN_LENGTH = 32; // 32 bytes = 64 hex characters

    private string $defaultTokenId;

    public function __construct(
        private SessionInterface $session,
        string $defaultTokenId = 'csrf_token'
    ) {
        $this->defaultTokenId = $defaultTokenId;
    }

    /**
     * Generate a new CSRF token for the given token ID
     *
     * @param string|null $tokenId Unique identifier for this token (e.g., 'login', 'delete_user')
     * @return CsrfToken
     */
    public function getToken(?string $tokenId = null): CsrfToken
    {
        $tokenId = $tokenId ?? $this->defaultTokenId;

        // Check if token already exists in session
        $tokens = $this->getSessionTokens();

        if (isset($tokens[$tokenId])) {
            return new CsrfToken($tokenId, $tokens[$tokenId]);
        }

        // Generate new token
        $value = $this->generateTokenValue();

        // Store in session
        $tokens[$tokenId] = $value;
        $this->setSessionTokens($tokens);

        return new CsrfToken($tokenId, $value);
    }

    /**
     * Refresh the token for the given token ID (generate new value)
     *
     * @param string|null $tokenId
     * @return CsrfToken
     */
    public function refreshToken(?string $tokenId = null): CsrfToken
    {
        $tokenId = $tokenId ?? $this->defaultTokenId;

        // Remove old token
        $this->removeToken($tokenId);

        // Generate new token
        return $this->getToken($tokenId);
    }

    /**
     * Validate a CSRF token
     *
     * Uses timing-safe comparison to prevent timing attacks.
     *
     * @param CsrfToken $token The token to validate
     * @return bool True if valid, false otherwise
     */
    public function isTokenValid(CsrfToken $token): bool
    {
        $tokens = $this->getSessionTokens();

        // Check if token exists in session
        if (!isset($tokens[$token->getId()])) {
            return false;
        }

        $expectedValue = $tokens[$token->getId()];
        $actualValue = $token->getValue();

        // Timing-safe comparison
        return hash_equals($expectedValue, $actualValue);
    }

    /**
     * Validate a token by ID and value
     *
     * Convenience method for direct validation without creating CsrfToken object.
     *
     * @param string $tokenId The token identifier
     * @param string $tokenValue The token value to validate
     * @return bool True if valid, false otherwise
     */
    public function validateToken(string $tokenId, string $tokenValue): bool
    {
        $token = new CsrfToken($tokenId, $tokenValue);
        return $this->isTokenValid($token);
    }

    /**
     * Remove a token from storage
     *
     * @param string|null $tokenId
     * @return void
     */
    public function removeToken(?string $tokenId = null): void
    {
        $tokenId = $tokenId ?? $this->defaultTokenId;

        $tokens = $this->getSessionTokens();
        unset($tokens[$tokenId]);
        $this->setSessionTokens($tokens);
    }

    /**
     * Check if a token exists in storage
     *
     * @param string|null $tokenId
     * @return bool
     */
    public function hasToken(?string $tokenId = null): bool
    {
        $tokenId = $tokenId ?? $this->defaultTokenId;
        $tokens = $this->getSessionTokens();
        return isset($tokens[$tokenId]);
    }

    /**
     * Get all stored token IDs
     *
     * @return array<string>
     */
    public function getTokenIds(): array
    {
        $tokens = $this->getSessionTokens();
        return array_keys($tokens);
    }

    /**
     * Clear all CSRF tokens from storage
     *
     * @return void
     */
    public function clear(): void
    {
        $this->setSessionTokens([]);
    }

    /**
     * Generate a cryptographically secure random token value
     *
     * @return string Hex-encoded random bytes
     */
    private function generateTokenValue(): string
    {
        return bin2hex(random_bytes(self::TOKEN_LENGTH));
    }

    /**
     * Get all tokens from session
     *
     * @return array<string, string>
     */
    private function getSessionTokens(): array
    {
        return $this->session->get(self::SESSION_KEY, []);
    }

    /**
     * Store tokens in session
     *
     * @param array<string, string> $tokens
     * @return void
     */
    private function setSessionTokens(array $tokens): void
    {
        $this->session->set(self::SESSION_KEY, $tokens);
    }
}
