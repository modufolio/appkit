<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\DataFixtures;

use Modufolio\Appkit\Factory\EntityFactory;
use Modufolio\Appkit\Tests\App\Entity\Account;
use Modufolio\Appkit\Tests\App\Entity\Contact;
use Modufolio\Appkit\Tests\App\Entity\Organization;
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
        // Create and persist the Account entity
        $account = new Account();
        $account->setName('Acme Corporation');

        $manager->persist($account);
        $manager->flush(); // Ensure the account is persisted for relationships
        $manager->refresh($account);

        // Create a test user with known credentials for authentication tests
        $this->entityFactory->create(User::class, [
            'email' => 'johndoe@example.com',
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'roles' => [],
        ]);

        // Create 5 additional Users for the Account
        $this->entityFactory->createMany(User::class, 5, [
            'account' => $account,
        ]);

        // Create 100 Organizations linked to the Account
        $this->entityFactory->createMany(Organization::class, 100, [
            'account' => $account,
        ]);

        // Create 100 Contacts linked to the Account and random Organizations
        $this->entityFactory->createMany(Contact::class, 100, [
            'account' => $account,
        ]);

        // Persist all entities to the database
        $this->entityFactory->store();
    }
}
