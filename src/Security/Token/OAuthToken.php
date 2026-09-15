<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Token;

use Modufolio\Appkit\Security\User\UserInterface;

/**
 * OAuth Token.
 *
 * Represents an authenticated OAuth 2.1 token
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class OAuthToken extends AbstractToken
{
    private string $firewallName;

    /**
     * @param list<string> $scopes
     * @param list<string> $roles
     */
    public function __construct(
        UserInterface $user,
        string $firewallName,
        private array $scopes = [],
        array $roles = [],
    ) {
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

    /**
     * @return list<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function __serialize(): array
    {
        return [null, $this->firewallName, $this->scopes, parent::__serialize()];
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        // Block gadget-chain "trampolines": a forged payload placing an object
        // in the string slot would otherwise fire its __toString on assignment.
        if (($data[1] ?? null) instanceof \Stringable) {
            throw new \BadMethodCallException('Cannot unserialize '.self::class);
        }

        [, $this->firewallName, $scopes, $parentData] = $data;

        // Scopes are a list of strings; anything else is forged or corrupt.
        // The parent payload is always an array (see __serialize()); reject
        // rather than calling unserialize() again, which would not inherit
        // the allowed_classes list TokenUnserializer passes.
        if (!\is_array($scopes) || !\is_array($parentData)) {
            throw new \BadMethodCallException('Cannot unserialize '.self::class);
        }

        $this->scopes = array_values(array_filter($scopes, 'is_string'));

        parent::__unserialize($parentData);
    }
}
