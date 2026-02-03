<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Security\User;

class UserPasswordHasher implements UserPasswordHasherInterface
{
    private string $algorithm;
    private array $options;

    public function __construct(array $options = [])
    {
        $this->algorithm = $options['algo'] ?? PASSWORD_ARGON2ID;
        $this->options = $options['options'] ?? ['cost' => 10];
    }

    public function hashPassword(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $plainPassword): string
    {
        return password_hash($plainPassword, $this->algorithm, $this->options);
    }

    public function isPasswordValid(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $plainPassword): bool
    {
        $hashedPassword = $user->getPassword();

        if (null === $hashedPassword) {
            return false;
        }

        return password_verify($plainPassword, $hashedPassword);
    }

    public function needsRehash(PasswordAuthenticatedUserInterface $user): bool
    {
        $hashedPassword = $user->getPassword();

        if (null === $hashedPassword) {
            return false;
        }

        return password_needs_rehash($hashedPassword, $this->algorithm, $this->options);
    }
}
