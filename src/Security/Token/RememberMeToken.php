<?php

namespace Modufolio\Appkit\Security\Token;

use Modufolio\Appkit\Security\User\UserInterface;

class RememberMeToken extends AbstractToken
{
    private string $firewallName;
    private string $secret;

    public function __construct(UserInterface $user, string $firewallName, string $secret, array $roles = [])
    {
        parent::__construct($roles);

        if ('' === $firewallName) {
            throw new \InvalidArgumentException('$firewallName must not be empty.');
        }

        if ('' === $secret) {
            throw new \InvalidArgumentException('$secret must not be empty.');
        }

        $this->setUser($user);
        $this->firewallName = $firewallName;
        $this->secret = $secret;
    }

    public function getFirewallName(): string
    {
        return $this->firewallName;
    }

    public function getSecret(): string
    {
        return $this->secret;
    }

    public function __serialize(): array
    {
        return [null, $this->firewallName, $this->secret, parent::__serialize()];
    }

    public function __unserialize(array $data): void
    {
        [, $this->firewallName, $this->secret, $parentData] = $data;
        $parentData = \is_array($parentData) ? $parentData : unserialize($parentData);
        parent::__unserialize($parentData);
    }
}
