<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\OAuth;

use Modufolio\Appkit\Security\User\UserInterface;

/**
 * Interface for OAuth Access Token Entity
 *
 * Defines the contract for OAuth 2.1 access token entities.
 * Consuming applications must implement this interface on their
 * ORM entity (or any storage-backed class).
 */
interface OAuthAccessTokenInterface
{
    public function getUser(): UserInterface;

    public function setUser(UserInterface $user): void;

    public function getToken(): string;

    public function setToken(string $token): void;

    public function getClientId(): string;

    public function setClientId(string $clientId): void;

    public function getGrantType(): string;

    public function setGrantType(string $grantType): void;

    /**
     * @return string[]
     */
    public function getScopes(): array;

    /**
     * @param string[] $scopes
     */
    public function setScopes(array $scopes): void;

    public function getExpiresAt(): \DateTimeImmutable;

    public function setExpiresAt(\DateTimeImmutable $expiresAt): void;

    public function getRefreshToken(): ?string;

    public function setRefreshToken(?string $refreshToken): void;

    public function getRefreshTokenExpiresAt(): ?\DateTimeImmutable;

    public function setRefreshTokenExpiresAt(?\DateTimeImmutable $expiresAt): void;

    public function isRefreshTokenExpired(): bool;

    public function isRevoked(): bool;

    public function revoke(): void;

    public function getLastUsedAt(): ?\DateTimeImmutable;

    public function setLastUsedAt(?\DateTimeImmutable $lastUsedAt): void;

    public function getIpAddress(): ?string;

    public function setIpAddress(?string $ipAddress): void;

    public function getUserAgent(): ?string;

    public function setUserAgent(?string $userAgent): void;

    /**
     * Get the plain (unhashed) access token.
     * Only available immediately after token creation.
     */
    public function getPlainAccessToken(): ?string;

    public function setPlainAccessToken(?string $token): void;

    /**
     * Get the plain (unhashed) refresh token.
     * Only available immediately after token creation.
     */
    public function getPlainRefreshToken(): ?string;

    public function setPlainRefreshToken(?string $token): void;
}
