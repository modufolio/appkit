<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security\User;

use Modufolio\Appkit\Security\Exception\UnsupportedUserException;
use Modufolio\Appkit\Security\Exception\UserNotFoundException;
use Modufolio\Appkit\Security\User\EntityUserProvider;
use Modufolio\Appkit\Security\User\InMemoryUser;
use Modufolio\Appkit\Tests\App\Entity\User;
use Modufolio\Appkit\Tests\Case\AppTestCase;

class EntityUserProviderTest extends AppTestCase
{
    private EntityUserProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshDatabase();
        $this->loadFixtures();

        $this->provider = new EntityUserProvider(
            $this->app()->entityManager(),
            User::class,
            'email',
        );
    }

    public function testLoadUserByIdentifierReturnsUser(): void
    {
        $user = $this->provider->loadUserByIdentifier('johndoe@example.com');

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('johndoe@example.com', $user->getEmail());
    }

    public function testLoadUserByIdentifierThrowsWhenMissing(): void
    {
        $this->expectException(UserNotFoundException::class);
        $this->provider->loadUserByIdentifier('nobody@example.com');
    }

    public function testRefreshUserReloadsFromDatabase(): void
    {
        $user = $this->provider->loadUserByIdentifier('johndoe@example.com');

        $refreshed = $this->provider->refreshUser($user);

        $this->assertInstanceOf(User::class, $refreshed);
        $this->assertSame($user->getId(), $refreshed->getId());
    }

    public function testRefreshUserThrowsForUnsupportedClass(): void
    {
        // A user that is not the configured entity class.
        $this->expectException(UnsupportedUserException::class);
        $this->provider->refreshUser(new InMemoryUser('someone@example.com', 'hash', ['ROLE_USER']));
    }

    public function testSupportsClass(): void
    {
        $this->assertTrue($this->provider->supportsClass(User::class));
        $this->assertFalse($this->provider->supportsClass(\stdClass::class));
    }

    public function testUpgradePasswordPersistsNewHash(): void
    {
        $user = $this->provider->loadUserByIdentifier('johndoe@example.com');
        $newHash = password_hash('rotated-secret', PASSWORD_BCRYPT);

        $this->provider->upgradePassword($user, $newHash);

        // Clear the identity map and reload to prove it persisted to the database.
        $this->app()->entityManager()->clear();
        $reloaded = $this->provider->loadUserByIdentifier('johndoe@example.com');

        $this->assertSame($newHash, $reloaded->getPassword());
    }
}
