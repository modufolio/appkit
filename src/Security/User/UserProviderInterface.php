<?php

namespace Modufolio\Appkit\Security\User;

use Modufolio\Appkit\Security\Exception\UserNotFoundException;

interface UserProviderInterface
{
    /**
     * Refreshes the user.
     *
     * It is up to the implementation to decide if the user data should be
     * totally reloaded (e.g. from the database), or if the UserInterface
     * object can just be merged into some internal array of users / identity
     * map.
     *
     * @throws UserNotFoundException if the user is not found
     */
    public function refreshUser(UserInterface $user): UserInterface;

    /**
     * Whether this provider supports the given user class.
     */
    public function supportsClass(string $class): bool;

    /**
     * Loads the user for the given user identifier (e.g. username or email).
     *
     * This method must throw UserNotFoundException if the user is not found.
     *
     * @throws UserNotFoundException
     */
    public function loadUserByIdentifier(string $identifier): UserInterface;



}
