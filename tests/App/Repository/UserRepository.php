<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Repository;

use Doctrine\ORM\EntityRepository;
use Modufolio\Appkit\Security\Exception\UserNotFoundException;
use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Modufolio\Appkit\Tests\App\Entity\User;

/**
 * @extends EntityRepository<User>
 */
class UserRepository extends EntityRepository implements UserProviderInterface
{
    public function findUserWithSecurityById(int $id): ?UserInterface
    {
        return $this->find($id);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        $id = $user->getId();
        $refreshedUser = $this->findUserWithSecurityById($id);

        if (!$refreshedUser) {
            throw new UserNotFoundException(sprintf('User with ID "%s" not found.', $id));
        }

        return $refreshedUser;
    }

    public function supportsClass(string $class): bool
    {
        return UserInterface::class === $class || User::class === $class;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->findOneBy(['email' => $identifier]);

        if (!$user) {
            throw new UserNotFoundException(sprintf('User with identifier "%s" not found.', $identifier));
        }

        return $user;
    }
}
