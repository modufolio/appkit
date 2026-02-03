<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Security\User;

interface UserInterface
{
    /**
     * Returns the unique identifier for this user.
     */
    public function getId(): mixed;

    /**
     * Returns the email address for this user.
     */
    public function getEmail(): string;

    /**
     * Returns the roles granted to the user.
     *
     *     public function getRoles()
     *     {
     *         return ['ROLE_USER'];
     *     }
     *
     * Alternatively, the roles might be stored in a ``roles`` property,
     * and populated in any number of different ways when the user object
     * is created.
     *
     * @return string[]
     */
    public function getRoles(): array;

    /**
     * Removes sensitive data from the user.
     *
     * This is important if, at any given point, sensitive information like
     * the plain-text password is stored on this object.
     */
    public function eraseCredentials(): void;

    /**
     * Returns the identifier for this user (e.g. username or email address).
     */
    public function getUserIdentifier(): string;
}
