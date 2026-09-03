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

    /**
     * The signing secret this token was minted with. Empty on a token restored
     * from a session — the secret is deliberately not persisted, see
     * __serialize().
     */
    public function getSecret(): string
    {
        return $this->secret;
    }

    public function __serialize(): array
    {
        // The secret is deliberately NOT serialized. It is the application-wide
        // remember-me signing key, so persisting it would put a copy at rest in
        // every session record: one read of the session store would then be
        // enough to forge remember-me cookies for every user, not just to
        // hijack the session it came from. Symfony omits it for the same
        // reason, and nothing reads it back off a restored token.
        return [null, $this->firewallName, parent::__serialize()];
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        // Block gadget-chain "trampolines": a forged payload placing an object
        // in a string slot would otherwise fire its __toString when assigned to
        // the typed property.
        if (($data[1] ?? null) instanceof \Stringable) {
            throw new \BadMethodCallException('Cannot unserialize '.self::class);
        }

        // Sessions written before the secret was dropped carry it in slot 2,
        // with the parent payload shifted one along. Read past it — the value
        // is discarded rather than restored.
        $parentData = 4 === \count($data) ? ($data[3] ?? null) : ($data[2] ?? null);
        $this->firewallName = $data[1];
        $this->secret = '';

        // See UsernamePasswordToken::__unserialize(): a nested unserialize()
        // would not inherit TokenUnserializer's allowed_classes list.
        if (!\is_array($parentData)) {
            throw new \BadMethodCallException('Cannot unserialize '.self::class);
        }

        parent::__unserialize($parentData);
    }
}
