<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Csrf;

/**
 * Interface for CSRF Token Management.
 *
 * @author    Maarten Thiebou
 *
 * @see       https://github.com/symfony/security-csrf
 *
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface CsrfTokenManagerInterface
{
    /**
     * Generate or retrieve a CSRF token for the given token ID.
     *
     * @param string|null $tokenId Unique identifier for this token
     */
    public function getToken(?string $tokenId = null): CsrfToken;

    /**
     * Refresh the token for the given token ID (generate new value).
     */
    public function refreshToken(?string $tokenId = null): CsrfToken;

    /**
     * Validate a CSRF token.
     *
     * @param CsrfToken $token The token to validate
     *
     * @return bool True if valid, false otherwise
     */
    public function isTokenValid(#[\SensitiveParameter] CsrfToken $token): bool;

    /**
     * Validate a token by ID and value.
     *
     * A missing or empty value is an ordinary invalid submission, not an
     * error: callers pass whatever the request contained, so this returns
     * false instead of throwing.
     *
     * @param string      $tokenId    The token identifier
     * @param string|null $tokenValue The token value to validate
     *
     * @return bool True if valid, false otherwise
     */
    public function validateToken(string $tokenId, ?string $tokenValue): bool;

    /**
     * Remove a token from storage.
     */
    public function removeToken(?string $tokenId = null): void;

    /**
     * Check if a token exists in storage.
     */
    public function hasToken(?string $tokenId = null): bool;

    /**
     * Clear all CSRF tokens from storage.
     */
    public function clear(): void;
}
