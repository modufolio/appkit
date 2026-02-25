<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App;

use Modufolio\Appkit\Security\Exception\UserNotFoundException;
use Modufolio\Appkit\Security\User\InMemoryUser;
use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;

/**
 * In-memory user provider for testing.
 * Stores users in an array keyed by identifier.
 */
class InMemoryUserProvider implements UserProviderInterface
{
    /** @var array<string, InMemoryUser> */
    private array $users = [];

    public function addUser(InMemoryUser $user): self
    {
        $this->users[$user->getUserIdentifier()] = $user;
        return $this;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        if (!isset($this->users[$identifier])) {
            throw new UserNotFoundException(sprintf('User "%s" not found.', $identifier));
        }

        return $this->users[$identifier];
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === InMemoryUser::class;
    }
}
