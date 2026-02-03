<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Csrf;

/**
 * Interface for CSRF Token Management
 */
interface CsrfTokenManagerInterface
{
    /**
     * Generate or retrieve a CSRF token for the given token ID
     *
     * @param string|null $tokenId Unique identifier for this token
     * @return CsrfToken
     */
    public function getToken(?string $tokenId = null): CsrfToken;

    /**
     * Refresh the token for the given token ID (generate new value)
     *
     * @param string|null $tokenId
     * @return CsrfToken
     */
    public function refreshToken(?string $tokenId = null): CsrfToken;

    /**
     * Validate a CSRF token
     *
     * @param CsrfToken $token The token to validate
     * @return bool True if valid, false otherwise
     */
    public function isTokenValid(CsrfToken $token): bool;

    /**
     * Validate a token by ID and value
     *
     * @param string $tokenId The token identifier
     * @param string $tokenValue The token value to validate
     * @return bool True if valid, false otherwise
     */
    public function validateToken(string $tokenId, string $tokenValue): bool;

    /**
     * Remove a token from storage
     *
     * @param string|null $tokenId
     * @return void
     */
    public function removeToken(?string $tokenId = null): void;

    /**
     * Check if a token exists in storage
     *
     * @param string|null $tokenId
     * @return bool
     */
    public function hasToken(?string $tokenId = null): bool;

    /**
     * Clear all CSRF tokens from storage
     *
     * @return void
     */
    public function clear(): void;
}
