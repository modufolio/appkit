<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App;

use Modufolio\Appkit\Security\OAuth\OAuthAccessTokenInterface;
use Modufolio\Appkit\Security\User\UserInterface;

/**
 * In-memory implementation of OAuthAccessTokenInterface for testing.
 */
class InMemoryOAuthAccessToken implements OAuthAccessTokenInterface
{
    private UserInterface $user;
    private string $token = '';
    private string $clientId = '';
    private string $grantType = '';
    private array $scopes = [];
    private \DateTimeImmutable $expiresAt;
    private ?string $refreshToken = null;
    private ?\DateTimeImmutable $refreshTokenExpiresAt = null;
    private bool $revoked = false;
    private ?\DateTimeImmutable $lastUsedAt = null;
    private ?string $ipAddress = null;
    private ?string $userAgent = null;
    private ?string $plainAccessToken = null;
    private ?string $plainRefreshToken = null;

    public function __construct()
    {
        $this->expiresAt = new \DateTimeImmutable('+1 hour');
    }

    public function getUser(): UserInterface { return $this->user; }
    public function setUser(UserInterface $user): void { $this->user = $user; }

    public function getToken(): string { return $this->token; }
    public function setToken(string $token): void { $this->token = $token; }

    public function getClientId(): string { return $this->clientId; }
    public function setClientId(string $clientId): void { $this->clientId = $clientId; }

    public function getGrantType(): string { return $this->grantType; }
    public function setGrantType(string $grantType): void { $this->grantType = $grantType; }

    public function getScopes(): array { return $this->scopes; }
    public function setScopes(array $scopes): void { $this->scopes = $scopes; }

    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $expiresAt): void { $this->expiresAt = $expiresAt; }

    public function getRefreshToken(): ?string { return $this->refreshToken; }
    public function setRefreshToken(?string $refreshToken): void { $this->refreshToken = $refreshToken; }

    public function getRefreshTokenExpiresAt(): ?\DateTimeImmutable { return $this->refreshTokenExpiresAt; }
    public function setRefreshTokenExpiresAt(?\DateTimeImmutable $expiresAt): void { $this->refreshTokenExpiresAt = $expiresAt; }

    public function isRefreshTokenExpired(): bool
    {
        if ($this->refreshTokenExpiresAt === null) {
            return true;
        }
        return $this->refreshTokenExpiresAt < new \DateTimeImmutable();
    }

    public function isRevoked(): bool { return $this->revoked; }
    public function revoke(): void { $this->revoked = true; }

    public function getLastUsedAt(): ?\DateTimeImmutable { return $this->lastUsedAt; }
    public function setLastUsedAt(?\DateTimeImmutable $lastUsedAt): void { $this->lastUsedAt = $lastUsedAt; }

    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function setIpAddress(?string $ipAddress): void { $this->ipAddress = $ipAddress; }

    public function getUserAgent(): ?string { return $this->userAgent; }
    public function setUserAgent(?string $userAgent): void { $this->userAgent = $userAgent; }

    public function getPlainAccessToken(): ?string { return $this->plainAccessToken; }
    public function setPlainAccessToken(?string $token): void { $this->plainAccessToken = $token; }

    public function getPlainRefreshToken(): ?string { return $this->plainRefreshToken; }
    public function setPlainRefreshToken(?string $token): void { $this->plainRefreshToken = $token; }
}
