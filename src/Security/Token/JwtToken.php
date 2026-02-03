<?php

namespace Modufolio\Appkit\Security\Token;

use Modufolio\Appkit\Security\User\UserInterface;

class JwtToken extends AbstractToken
{
    private string $firewallName;
    private array $payload;

    public function __construct(UserInterface $user, string $firewallName, array $payload = [], array $roles = [])
    {
        parent::__construct($roles);

        if ('' === $firewallName) {
            throw new \InvalidArgumentException('$firewallName must not be empty.');
        }

        $this->setUser($user);
        $this->firewallName = $firewallName;
        $this->payload = $payload;
    }

    public function getFirewallName(): string
    {
        return $this->firewallName;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function __serialize(): array
    {
        return [null, $this->firewallName, $this->payload, parent::__serialize()];
    }

    public function __unserialize(array $data): void
    {
        [, $this->firewallName, $this->payload, $parentData] = $data;
        $parentData = \is_array($parentData) ? $parentData : unserialize($parentData);
        parent::__unserialize($parentData);
    }
}
