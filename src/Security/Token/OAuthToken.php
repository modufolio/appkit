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
}
