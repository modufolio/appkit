<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Token;

use Modufolio\Appkit\Security\User\UserInterface;

/**
 * Partial authentication token for 2FA flow
 *
 * This token represents a user who has successfully authenticated with their
 * password but still needs to provide their second factor (TOTP/backup code).
 *
 * This token is NOT fully authenticated and should not grant access to protected resources.
 */
class TwoFactorToken extends AbstractToken
{
    private \DateTimeImmutable $createdAt;
    private string $firewallName;

    public function __construct(
        UserInterface $user,
        string $firewallName,
        array $roles = []
    ) {
        parent::__construct($roles);

        if ('' === $firewallName) {
            throw new \InvalidArgumentException('$firewallName must not be empty.');
        }

        $this->setUser($user);
        $this->firewallName = $firewallName;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getFirewallName(): string
    {
        return $this->firewallName;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Check if token has expired (10 minutes)
     */
    public function isExpired(): bool
    {
        $now = new \DateTimeImmutable();
        $diff = $now->getTimestamp() - $this->createdAt->getTimestamp();
        return $diff > 600; // 10 minutes
    }

    public function __serialize(): array
    {
        return [$this->firewallName, $this->createdAt->format('c'), parent::__serialize()];
    }

    public function __unserialize(array $data): void
    {
        [$this->firewallName, $createdAt, $parentData] = $data;
        $parentData = \is_array($parentData) ? $parentData : unserialize($parentData);
        parent::__unserialize($parentData);
        $this->createdAt = new \DateTimeImmutable($createdAt);
    }
}
