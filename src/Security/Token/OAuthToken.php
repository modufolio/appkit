<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Token;

use Modufolio\Appkit\Security\User\UserInterface;

/**
 * OAuth Token
 *
 * Represents an authenticated OAuth 2.1 token
 */
class OAuthToken extends AbstractToken
{
    private string $firewallName;

    public function __construct(
        UserInterface $user,
        string $firewallName,
        private array $scopes = [],
        array $roles = []
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

    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
