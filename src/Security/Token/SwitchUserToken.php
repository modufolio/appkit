<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Token;

use Modufolio\Appkit\Security\User\UserInterface;

/**
 * Switch User Token for user impersonation
 *
 * This token wraps the original authenticated token and allows administrators
 * to impersonate other users for debugging and support purposes.
 *
 * The original token can be retrieved to restore the original user session.
 */
class SwitchUserToken extends AbstractToken
{
    private TokenInterface $originalToken;
    private string $firewallName;

    public function __construct(
        UserInterface $user,
        string $firewallName,
        array $roles,
        TokenInterface $originalToken
    ) {
        parent::__construct($roles);

        if ('' === $firewallName) {
            throw new \InvalidArgumentException('$firewallName must not be empty.');
        }

        $this->setUser($user);
        $this->firewallName = $firewallName;
        $this->originalToken = $originalToken;

        // Set a special role to identify impersonation
        if (!in_array('ROLE_PREVIOUS_ADMIN', $this->getRoleNames())) {
            $this->setAttribute('ROLE_PREVIOUS_ADMIN', true);
        }
    }

    /**
     * Get the original token before user switch
     */
    public function getOriginalToken(): TokenInterface
    {
        return $this->originalToken;
    }

    /**
     * Get the firewall name
     */
    public function getFirewallName(): string
    {
        return $this->firewallName;
    }

    /**
     * Check if this is an impersonation token
     */
    public function isImpersonating(): bool
    {
        return true;
    }

    public function __serialize(): array
    {
        return [$this->firewallName, $this->originalToken, parent::__serialize()];
    }

    public function __unserialize(array $data): void
    {
        [$this->firewallName, $this->originalToken, $parentData] = $data;
        $parentData = \is_array($parentData) ? $parentData : unserialize($parentData);
        parent::__unserialize($parentData);
    }
}
