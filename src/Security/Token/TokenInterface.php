<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Security\Token;

use Modufolio\Appkit\Security\User\UserInterface;

interface TokenInterface extends \Stringable
{
    /**
     * Returns a string representation of the Token.
     *
     * This is only to be used for debugging purposes.
     */
    public function __toString(): string;

    /**
     * Returns the user identifier used during authentication (e.g. a user's email address or username).
     */
    public function getUserIdentifier(): string;

    /**
     * Returns the user roles.
     *
     * @return string[]
     */
    public function getRoleNames(): array;

    /**
     * Returns a user representation.
     *
     * @see AbstractToken::setUser()
     */
    public function getUser(): ?UserInterface;

    /**
     * Sets the authenticated user in the token.
     *
     * @throws \InvalidArgumentException
     */
    public function setUser(UserInterface $user): void;

    /**
     * Removes sensitive information from the token.
     */
    public function eraseCredentials(): void;

    public function getAttributes(): array;

    /**
     * @param array $attributes The token attributes
     */
    public function setAttributes(array $attributes): void;

    public function hasAttribute(string $name): bool;

    /**
     * @throws \InvalidArgumentException When attribute doesn't exist for this token
     */
    public function getAttribute(string $name): mixed;

    public function setAttribute(string $name, mixed $value): void;

    /**
     * Returns all the necessary state of the object for serialization purposes.
     */
    public function __serialize(): array;

    /**
     * Restores the object state from an array given by __serialize().
     */
    public function __unserialize(array $data): void;
}
