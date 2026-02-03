<?php

namespace Modufolio\Appkit\Security\Token;

use Modufolio\Appkit\Security\User\UserInterface;

class ApiKeyToken extends AbstractToken
{
    private string $firewallName;
    private ?string $apiKey;

    public function __construct(UserInterface $user, string $firewallName, ?string $apiKey = null, array $roles = [])
    {
        parent::__construct($roles);

        if ('' === $firewallName) {
            throw new \InvalidArgumentException('$firewallName must not be empty.');
        }

        $this->setUser($user);
        $this->firewallName = $firewallName;
        $this->apiKey = $apiKey ?: null;
    }

    public function getFirewallName(): string
    {
        return $this->firewallName;
    }

    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    public function __serialize(): array
    {
        return [null, $this->firewallName, $this->apiKey, parent::__serialize()];
    }

    public function __unserialize(array $data): void
    {
        [, $this->firewallName, $this->apiKey, $parentData] = $data;
        $parentData = \is_array($parentData) ? $parentData : unserialize($parentData);
        parent::__unserialize($parentData);
    }
}
