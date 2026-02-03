<?php

namespace Modufolio\Appkit\Security\Token;

use Modufolio\Appkit\Security\User\UserInterface;

class UsernamePasswordToken extends AbstractToken
{
    private string $firewallName;

    public function __construct(UserInterface $user, string $firewallName, array $roles = [])
    {
        parent::__construct($roles);

        if ('' === $firewallName) {
            throw new \InvalidArgumentException('$firewallName must not be empty.');
        }

        $this->setUser($user);
        $this->firewallName = $firewallName;
    }

    public function getFirewallName(): string
    {
        return $this->firewallName;
    }

    public function __serialize(): array
    {
        return [null, $this->firewallName, parent::__serialize()];
    }

    public function __unserialize(array $data): void
    {
        [, $this->firewallName, $parentData] = $data;
        $parentData = \is_array($parentData) ? $parentData : unserialize($parentData);
        parent::__unserialize($parentData);
    }
}
