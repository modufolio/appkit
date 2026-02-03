<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Security\User;

interface PasswordAuthenticatedUserInterface extends UserInterface
{
    /**
     * Returns the hashed password used to authenticate the user.
     *
     * Usually on authentication, a plain-text password will be compared to this value.
     */
    public function getPassword(): ?string;
}
