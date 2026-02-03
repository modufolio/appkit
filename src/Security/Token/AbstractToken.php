<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Security\Token;

use Modufolio\Appkit\Security\User\InMemoryUser;
use Modufolio\Appkit\Security\User\UserInterface;

abstract class AbstractToken implements TokenInterface, \Serializable
{
    private ?UserInterface $user = null;
    private array $roleNames = [];
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

    public function __unserialize(array $data): void
    {
        [$user, , , $this->attributes, $this->roleNames] = $data;
        $this->user = \is_string($user) ? new InMemoryUser($user, '', $this->roleNames, false) : $user;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

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

    final public function serialize(): string
    {
        throw new \BadMethodCallException('Cannot serialize ' . __CLASS__);
    }

    final public function unserialize(string $serialized): void
    {
        $this->__unserialize(unserialize($serialized));
    }
}
