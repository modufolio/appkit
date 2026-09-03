<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Token;

use Modufolio\Appkit\Security\User\UserInterface;

/**
 * Switch User Token for user impersonation.
 *
 * This token wraps the original authenticated token and allows administrators
 * to impersonate other users for debugging and support purposes.
 *
 * The original token can be retrieved to restore the original user session.
 *
 * @author    Maarten Thiebou
 *
 * @see       https://github.com/symfony/security-core
 *
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class SwitchUserToken extends AbstractToken
{
    private TokenInterface $originalToken;
    private string $firewallName;

    /**
     * @param string|null $originatedFromUri the URI the impersonator was on when
     *                                       switching, so exiting can return there
     */
    public function __construct(
        UserInterface $user,
        string $firewallName,
        array $roles,
        #[\SensitiveParameter] TokenInterface $originalToken,
        private ?string $originatedFromUri = null,
    ) {
        parent::__construct($roles);

        if ('' === $firewallName) {
            throw new \InvalidArgumentException('$firewallName must not be empty.');
        }

        $this->setUser($user);
        $this->firewallName = $firewallName;
        $this->originalToken = $originalToken;

        // Set a special role to identify impersonation
        if (!in_array('ROLE_PREVIOUS_ADMIN', $this->getRoleNames(), true)) {
            $this->setAttribute('ROLE_PREVIOUS_ADMIN', true);
        }
    }

    /**
     * Get the original token before user switch.
     */
    public function getOriginalToken(): TokenInterface
    {
        return $this->originalToken;
    }

    /**
     * Get the firewall name.
     */
    public function getFirewallName(): string
    {
        return $this->firewallName;
    }

    /**
     * Check if this is an impersonation token.
     */
    public function isImpersonating(): bool
    {
        return true;
    }

    /**
     * The URI the impersonator was on when the switch happened, if recorded.
     */
    public function getOriginatedFromUri(): ?string
    {
        return $this->originatedFromUri;
    }

    public function __serialize(): array
    {
        return [$this->firewallName, $this->originalToken, parent::__serialize(), $this->originatedFromUri];
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        // Block gadget-chain "trampolines": a forged payload placing an object
        // in a string slot would otherwise fire its __toString on assignment.
        if (($data[0] ?? null) instanceof \Stringable || ($data[3] ?? null) instanceof \Stringable) {
            throw new \BadMethodCallException('Cannot unserialize '.self::class);
        }

        // The URI is appended last so sessions serialized before it existed
        // (3-element payloads) still restore — they simply carry no URI.
        [$this->firewallName, $this->originalToken, $parentData] = $data;
        $this->originatedFromUri = $data[3] ?? null;

        // See UsernamePasswordToken::__unserialize(): a nested unserialize()
        // would not inherit TokenUnserializer's allowed_classes list.
        if (!\is_array($parentData)) {
            throw new \BadMethodCallException('Cannot unserialize '.self::class);
        }

        parent::__unserialize($parentData);
    }
}
