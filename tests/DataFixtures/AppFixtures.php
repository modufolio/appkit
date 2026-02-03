<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\DataFixtures;

use Modufolio\Appkit\Factory\EntityFactory;
use Modufolio\Appkit\Tests\App\Entity\User;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Persistence\ObjectManager;

class AppFixtures implements FixtureInterface
{
    public function __construct(private EntityFactory $entityFactory)
    {
    }

    public function load(ObjectManager $manager): void
    {
        // Create a test user with known credentials for authentication tests
        $this->entityFactory->create(User::class, [
            'email' => 'johndoe@example.com',
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'roles' => [],
        ]);

        // Create a few additional test users
        $this->entityFactory->createMany(User::class, 5, [
            'roles' => [],
        ]);

        // Persist all entities to the database
        $this->entityFactory->store();
    }
}
