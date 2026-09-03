<?php

namespace Modufolio\Appkit\Security\Token;

use Modufolio\Appkit\Security\User\UserInterface;

/**
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class ApiKeyToken extends AbstractToken
{
    private string $firewallName;
    private ?string $apiKey;

    public function __construct(UserInterface $user, string $firewallName, #[\SensitiveParameter] ?string $apiKey = null, array $roles = [])
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

    /**
     * @param array<int|string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        // Block gadget-chain "trampolines": a forged payload placing an object
        // in a string slot (firewallName, apiKey) would otherwise fire its
        // __toString when assigned to the typed property.
        if (($data[1] ?? null) instanceof \Stringable || ($data[2] ?? null) instanceof \Stringable) {
            throw new \BadMethodCallException('Cannot unserialize '.self::class);
        }

        [, $this->firewallName, $this->apiKey, $parentData] = $data;
        // The parent payload is always an array (see __serialize()); anything
        // else is forged or corrupt. Reject it rather than calling unserialize()
        // again: a nested call does NOT inherit the allowed_classes list that
        // TokenUnserializer passes, so it would happily construct any
        // autoloadable class and defeat the allowlist entirely.
        if (!\is_array($parentData)) {
            throw new \BadMethodCallException('Cannot unserialize '.self::class);
        }

        parent::__unserialize($parentData);
    }
}
