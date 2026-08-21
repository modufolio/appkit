<?php

namespace Modufolio\Appkit\Security\Token;

use Modufolio\Appkit\Security\User\UserInterface;

/**
 * @author    Maarten Thiebou
 *
 * @see       https://github.com/symfony/security-core
 *
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class RememberMeToken extends AbstractToken
{
    private string $firewallName;
    private string $secret;

    public function __construct(UserInterface $user, string $firewallName, #[\SensitiveParameter] string $secret, array $roles = [])
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

    /**
     * @param array<int|string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        // Block gadget-chain "trampolines": a forged payload placing an object
        // in a string slot (firewallName, secret) would otherwise fire its
        // __toString when assigned to the typed property.
        if (($data[1] ?? null) instanceof \Stringable || ($data[2] ?? null) instanceof \Stringable) {
            throw new \BadMethodCallException('Cannot unserialize '.self::class);
        }

        [, $this->firewallName, $this->secret, $parentData] = $data;
        parent::__unserialize($parentData);
    }
}
