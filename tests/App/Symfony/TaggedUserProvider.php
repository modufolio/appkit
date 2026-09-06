<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Symfony;

use Modufolio\Appkit\Security\Exception\UserNotFoundException;
use Modufolio\Appkit\Security\User\InMemoryUser;
use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;

/**
 * A user provider a module would tag appkit.user_provider: one known user,
 * enough to prove the firewall's provider can come from the Symfony graph.
 */
final class TaggedUserProvider implements UserProviderInterface
{
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        if ('ada' !== $identifier) {
            throw new UserNotFoundException(sprintf('User "%s" not found.', $identifier));
        }

        return new InMemoryUser('ada', null, ['ROLE_USER']);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return InMemoryUser::class === $class;
    }
}
