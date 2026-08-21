<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\Token;

use Modufolio\Appkit\Security\User\InMemoryUser;
use Modufolio\Appkit\Security\User\UserInterface;

/**
 * @author    Fabien Potencier <fabien@symfony.com>
 * @author    Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * @see       https://github.com/symfony/security-core
 *
 * @copyright Fabien Potencier <fabien@symfony.com>
 * @license   https://opensource.org/licenses/MIT
 */
abstract class AbstractToken implements TokenInterface
{
    private ?UserInterface $user = null;
    /** @var list<string> */
    private array $roleNames = [];

    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param string[] $roles An array of roles
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(array $roles = [])
    {
        foreach ($roles as $role) {
            $this->roleNames[] = $role;
        }
    }

    public function getRoleNames(): array
    {
        return $this->roleNames;
    }

    /**
     * Alias for getRoleNames() for consistency with UserInterface.
     *
     * @return string[]
     */
    public function getRoles(): array
    {
        return $this->getRoleNames();
    }

    public function getUserIdentifier(): string
    {
        return $this->user ? $this->user->getUserIdentifier() : '';
    }

    public function getUser(): ?UserInterface
    {
        return $this->user;
    }

    public function setUser(UserInterface $user): void
    {
        $this->user = $user;
    }

    public function eraseCredentials(): void
    {
        if ($this->getUser() instanceof UserInterface) {
            $this->getUser()->eraseCredentials();
        }
    }

    public function __serialize(): array
    {
        return [$this->user, true, null, $this->attributes, $this->roleNames];
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        [$user, , , $attributes, $roleNames] = $data;
        $this->attributes = is_array($attributes) ? $attributes : [];
        $this->roleNames = is_array($roleNames) ? array_values(array_map(strval(...), $roleNames)) : [];
        $this->user = \is_string($user) ? new InMemoryUser($user, '', $this->roleNames, false) : $user;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function setAttributes(array $attributes): void
    {
        $this->attributes = $attributes;
    }

    public function hasAttribute(string $name): bool
    {
        return \array_key_exists($name, $this->attributes);
    }

    public function getAttribute(string $name): mixed
    {
        if (!\array_key_exists($name, $this->attributes)) {
            throw new \InvalidArgumentException(sprintf('This token has no "%s" attribute.', $name));
        }

        return $this->attributes[$name];
    }

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function __toString(): string
    {
        $class = static::class;
        $class = substr($class, strrpos($class, '\\') + 1);

        $roles = [];
        foreach ($this->roleNames as $role) {
            $roles[] = $role;
        }

        return sprintf('%s(user="%s", roles="%s")', $class, $this->getUserIdentifier(), implode(', ', $roles));
    }
}
