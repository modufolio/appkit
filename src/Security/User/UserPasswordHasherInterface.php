<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Security\User;

interface UserPasswordHasherInterface
{
    /**
     * Hashes the plain password for the given user.
     */
    public function hashPassword(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $plainPassword): string;

    /**
     * Checks if the plaintext password matches the user's password.
     */
    public function isPasswordValid(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $plainPassword): bool;

    /**
     * Checks if an encoded password would benefit from rehashing.
     */
    public function needsRehash(PasswordAuthenticatedUserInterface $user): bool;
}
