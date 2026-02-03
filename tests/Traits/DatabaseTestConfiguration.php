<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Tests\Traits;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Exception\TypesException;
use Doctrine\DBAL\Types\Types;

/**
 * Configuration trait for DatabaseCase providing additional utilities.
 */
trait DatabaseTestConfiguration
{
    /**
     * Schema definition for test database setup.
     * @throws TypesException
     */
    public function getTestSchema(): Schema
    {
        $schema = new Schema();

        // Users table
        $users = $schema->createTable('users');
        $users->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $users->addColumn('name', Types::STRING, ['length' => 255]);
        $users->addColumn('email', Types::STRING, ['length' => 255]);
        $users->addColumn('password', Types::STRING, ['length' => 255, 'notnull' => false]);
        $users->addColumn('status', Types::STRING, ['length' => 50, 'default' => 'active']);
        $users->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $users->addColumn('updated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $users->setPrimaryKey(['id']);
        $users->addUniqueIndex(['email'], 'uniq_users_email');
        $users->addIndex(['status'], 'idx_users_status');

        // User roles table
        $userRoles = $schema->createTable('user_roles');
        $userRoles->addColumn('user_id', Types::INTEGER);
        $userRoles->addColumn('role', Types::STRING, ['length' => 50]);
        $userRoles->addColumn('granted_at', Types::DATETIME_IMMUTABLE);
        $userRoles->setPrimaryKey(['user_id', 'role']);
        $userRoles->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);

        // User activities table
        $activities = $schema->createTable('user_activities');
        $activities->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $activities->addColumn('user_id', Types::INTEGER);
        $activities->addColumn('action', Types::STRING, ['length' => 100]);
        $activities->addColumn('details', Types::JSON, ['notnull' => false]);
        $activities->addColumn('ip_address', Types::STRING, ['length' => 45, 'notnull' => false]);
        $activities->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $activities->setPrimaryKey(['id']);
        $activities->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);
        $activities->addIndex(['user_id', 'created_at'], 'idx_activities_user_date');

        // Sessions table
        $sessions = $schema->createTable('user_sessions');
        $sessions->addColumn('id', Types::STRING, ['length' => 128]);
        $sessions->addColumn('user_id', Types::INTEGER);
        $sessions->addColumn('data', Types::TEXT);
        $sessions->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $sessions->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $sessions->setPrimaryKey(['id']);
        $sessions->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);

        return $schema;
    }

    /**
     * Get default test fixtures.
     */
    protected function getDefaultFixtures(): array
    {
        $now = new \DateTimeImmutable();

        return [
            'users' => [
                [
                    'id' => 1,
                    'name' => 'Admin User',
                    'email' => 'admin@example.com',
                    'password' => password_hash('password', PASSWORD_DEFAULT),
                    'status' => 'active',
                    'created_at' => $now->format('Y-m-d H:i:s'),
                    'updated_at' => $now->format('Y-m-d H:i:s'),
                ],
                [
                    'id' => 2,
                    'name' => 'Regular User',
                    'email' => 'user@example.com',
                    'password' => password_hash('password', PASSWORD_DEFAULT),
                    'status' => 'active',
                    'created_at' => $now->modify('-7 days')->format('Y-m-d H:i:s'),
                    'updated_at' => $now->modify('-2 days')->format('Y-m-d H:i:s'),
                ],
                [
                    'id' => 3,
                    'name' => 'Inactive User',
                    'email' => 'inactive@example.com',
                    'password' => password_hash('password', PASSWORD_DEFAULT),
                    'status' => 'inactive',
                    'created_at' => $now->modify('-30 days')->format('Y-m-d H:i:s'),
                    'updated_at' => $now->modify('-10 days')->format('Y-m-d H:i:s'),
                ],
            ],
            'user_roles' => [
                [
                    'user_id' => 1,
                    'role' => 'ROLE_ADMIN',
                    'granted_at' => $now->format('Y-m-d H:i:s'),
                ],
                [
                    'user_id' => 1,
                    'role' => 'ROLE_USER',
                    'granted_at' => $now->format('Y-m-d H:i:s'),
                ],
                [
                    'user_id' => 2,
                    'role' => 'ROLE_USER',
                    'granted_at' => $now->modify('-7 days')->format('Y-m-d H:i:s'),
                ],
                [
                    'user_id' => 3,
                    'role' => 'ROLE_USER',
                    'granted_at' => $now->modify('-30 days')->format('Y-m-d H:i:s'),
                ],
            ],
        ];
    }

    /**
     * Generate test data dynamically.
     */
    protected function generateTestUsers(int $count): array
    {
        $users = [];
        $faker = \Faker\Factory::create();

        for ($i = 1; $i <= $count; $i++) {
            $users[] = [
                'id' => $i,
                'name' => $faker->name(),
                'email' => $faker->unique()->safeEmail(),
                'password' => password_hash('password', PASSWORD_DEFAULT),
                'status' => $faker->randomElement(['active', 'inactive', 'suspended']),
                'created_at' => $faker->dateTimeBetween('-1 year', 'now')->format('Y-m-d H:i:s'),
                'updated_at' => $faker->dateTimeBetween('-1 month', 'now')->format('Y-m-d H:i:s'),
            ];
        }

        return $users;
    }
}
