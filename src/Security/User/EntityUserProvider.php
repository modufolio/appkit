<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security\User;

use Doctrine\ORM\EntityManagerInterface;
use Modufolio\Appkit\Security\Exception\UnsupportedUserException;
use Modufolio\Appkit\Security\Exception\UserNotFoundException;

/**
 * Doctrine-backed user provider.
 *
 * Loads users by an identifier property (e.g. email) and reloads them from the
 * database on refresh so role/password changes surface. Drop-in for apps that
 * would otherwise hand-roll loadUserByIdentifier/refreshUser/supportsClass on a
 * repository.
 */
class EntityUserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    /**
     * @param class-string<UserInterface> $entityClass
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $entityClass,
        private string $identifierProperty = 'email',
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->entityManager->getRepository($this->entityClass)
            ->findOneBy([$this->identifierProperty => $identifier]);

        if (!$user instanceof UserInterface) {
            throw new UserNotFoundException(sprintf('User "%s" not found.', $identifier));
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$this->supportsClass($user::class)) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $refreshed = $this->entityManager->getRepository($this->entityClass)->find($user->getId());

        if (!$refreshed instanceof UserInterface) {
            throw new UserNotFoundException(sprintf('User with id "%s" could not be reloaded.', (string) $user->getId()));
        }

        return $refreshed;
    }

    public function supportsClass(string $class): bool
    {
        return $class === $this->entityClass || is_subclass_of($class, $this->entityClass);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $newHashedPassword): void
    {
        // The entity must be of our type and expose a setter to persist the hash.
        if (!$user instanceof $this->entityClass || !method_exists($user, 'setPassword')) {
            return;
        }

        $user->setPassword($newHashedPassword);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }
}
