<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App;

use Modufolio\Appkit\Security\User\UserInterface;

/**
 * A simple user that does NOT implement PasswordAuthenticatedUserInterface.
 *
 * Used to test the "user does not support password authentication" path.
 */
class NonPasswordUser implements UserInterface
{
    public function __construct(
        private string $identifier,
        private array $roles = ['ROLE_USER'],
    ) {
    }

    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function eraseCredentials(): void
    {
    }

    public function getId(): mixed
    {
        return $this->identifier;
    }

    public function getEmail(): string
    {
        return $this->identifier;
    }
}
