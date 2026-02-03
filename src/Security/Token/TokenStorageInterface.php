<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Security\Token;

interface TokenStorageInterface
{
    /**
     * Returns the current security token.
     */
    public function getToken(): ?TokenInterface;

    /**
     * Sets the authentication token.
     *
     * @param TokenInterface|null $token A TokenInterface token, or null if no further authentication information should be stored
     */
    public function setToken(?TokenInterface $token): void;
}
