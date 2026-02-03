<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Database;

use Modufolio\Appkit\Tests\Traits\DatabaseTestingCapabilities;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Exception\TypesException;
use PHPUnit\Framework\TestCase;

/**
 * Example test demonstrating how to use the enhanced DatabaseCase
 * with automatic query tracking via DebugMiddleware.
 */
final class ExampleDatabaseTest extends TestCase
{
    use DatabaseTestingCapabilities;


    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        // Ensure a clean state before each test
        $this->createTestSchema();

        $this->debugStack->resetQueries();
        $this->resetTracking();
        $this->cleanupTables = ['users', 'posts'];
    }

    /**
     * Define your test schema.
     * @throws TypesException
     */
    public function getTestSchema(): Schema
    {
        $schema = new Schema();

        $usersTable = $schema->createTable('users');
        $usersTable->addColumn('id', 'integer', ['autoincrement' => true]);
        $usersTable->addColumn('name', 'string', ['length' => 255]);
        $usersTable->addColumn('email', 'string', ['length' => 255]);
        $usersTable->addColumn('created_at', 'datetime');
        $usersTable->setPrimaryKey(['id']);
        $usersTable->addUniqueIndex(['email'], 'uniq_users_email');

        $postsTable = $schema->createTable('posts');
        $postsTable->addColumn('id', 'integer', ['autoincrement' => true]);
        $postsTable->addColumn('user_id', 'integer');
        $postsTable->addColumn('title', 'string', ['length' => 255]);
        $postsTable->addColumn('content', 'text');
        $postsTable->addColumn('created_at', 'datetime');
        $postsTable->setPrimaryKey(['id']);
        $postsTable->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);

        return $schema;
    }




    /**
     * Test basic CRUD operations with query tracking.
     * @throws Exception
     */
    public function testBasicCrudOperations(): void
    {
        // Insert a user
        $this->connection->insert('users', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Insert a post
        $this->connection->insert('posts', [
            'user_id' => 1,
            'title' => 'My First Post',
            'content' => 'This is the content',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Query the data
        $user = $this->connection->fetchAssociative('SELECT * FROM users WHERE id = ?', [1]);
        $post = $this->connection->fetchAssociative('SELECT * FROM posts WHERE user_id = ?', [1]);

        // Basic assertions
        $this->assertEquals('John Doe', $user['name']);
        $this->assertEquals('My First Post', $post['title']);

        // Query tracking assertions
        // Note: Exact counts may vary depending on schema creation queries
        $this->assertQueryCount(2, 'INSERT'); // 2 inserts
        $this->assertQueryCount(2, 'SELECT'); // 2 selects

        // Assert specific tables were queried
        $this->assertTableQueried('users', 'INSERT');
        $this->assertTableQueried('posts', 'INSERT');
        $this->assertTableQueried('users', 'SELECT');
        $this->assertTableQueried('posts', 'SELECT');

        // Assert specific query patterns
        $this->assertQueryExecuted('/INSERT INTO users/', 1);
        $this->assertQueryExecuted('/INSERT INTO posts/', 1);

        // Performance assertions
        $this->assertNoSlowQueries();

        // Get performance report
        $report = $this->getPerformanceReport();
        $this->assertGreaterThan(0, $report['total_queries']);
        $this->assertGreaterThan(0, $report['total_time']);
    }

    /**
     * Test query counting by type.
     * @throws Exception
     */
    public function testQueryCountingByType(): void
    {
        // Perform various operations
        $this->connection->insert('users', [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->connection->insert('users', [
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->connection->executeStatement('UPDATE users SET name = ? WHERE email = ?', [
            'Alice Smith',
            'alice@example.com'
        ]);

        $this->connection->fetchAllAssociative('SELECT * FROM users');

        // Assert query counts by type
        $this->assertQueryCount(2, 'INSERT');
        $this->assertQueryCount(1, 'UPDATE');
        $this->assertQueryCount(1, 'SELECT');
    }

    /**
     * Test asserting specific tables were NOT queried.
     * @throws Exception
     */
    public function testTableNotQueried(): void
    {
        // Only query users table
        $this->connection->insert('users', [
            'name' => 'David',
            'email' => 'david@example.com',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->connection->fetchAllAssociative('SELECT * FROM users');

        // Assert posts table was never queried
        $this->assertTableNotQueried('posts');

        // This would fail:
        // $this->assertTableNotQueried('users');
    }

    /**
     * Test query performance assertions.
     * @throws Exception
     */
    public function testQueryPerformance(): void
    {
        // Set a very lenient threshold for this test
        $this->setSlowQueryThreshold(10.0);

        $this->connection->insert('users', [
            'name' => 'Eve',
            'email' => 'eve@example.com',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Assert no slow queries (with our 10s threshold, this should pass)
        $this->assertNoSlowQueries();

        // Assert specific query performance
        $this->assertQueryPerformance('/INSERT INTO users/', 1.0);
    }

    /**
     * Test query log filtering.
     * @throws Exception
     */
    public function testQueryLogFiltering(): void
    {
        // Perform various operations
        $this->connection->insert('users', [
            'name' => 'Frank',
            'email' => 'frank@example.com',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $id = $this->connection->lastInsertId();

        $this->connection->insert('posts', [
            'user_id' => $id,
            'title' => 'Test Post',
            'content' => 'Content',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->connection->fetchAllAssociative('SELECT * FROM users');

        // Get all INSERT queries
        $insertQueries = $this->getQueryLog('INSERT');
        $this->assertCount(2, $insertQueries);

        // Get queries for specific table
        $userQueries = $this->getQueryLog(null, 'users');
        $this->assertNotEmpty($userQueries);

        // Get INSERT queries for users table
        $userInserts = $this->getQueryLog('INSERT', 'users');
        $this->assertCount(1, $userInserts);
    }

    /**
     * Test using debug stack directly.
     * @throws Exception
     */
    public function testDebugStackAccess(): void
    {
        $this->connection->insert('users', [
            'name' => 'Grace',
            'email' => 'grace@example.com',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Access debug stack directly
        $queries = $this->getDebugQueries();
        $this->assertNotEmpty($queries);

        // Get last query
        $lastQuery = $this->getLastDebugQuery();
        $this->assertNotNull($lastQuery);
        $this->assertStringContainsString('INSERT INTO users', $lastQuery->sql);

        // Find specific queries
        $insertQueries = $this->findDebugQueries('/INSERT INTO users/');
        $this->assertNotEmpty($insertQueries);

        // Get execution time
        $totalTime = $this->getDebugTotalExecutionTime();
        $this->assertGreaterThan(0, $totalTime);
    }

    /**
     * Test performance reporting.
     * @throws Exception
     */
    public function testPerformanceReporting(): void
    {
        // Perform some operations
        for ($i = 1; $i <= 5; $i++) {
            $this->connection->insert('users', [
                'name' => "User {$i}",
                'email' => "user{$i}@example.com",
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $this->connection->fetchAllAssociative('SELECT * FROM users');

        // Get performance report
        $report = $this->getPerformanceReport();

        $this->assertEquals(5, $report['queries_by_type']['INSERT']);
        $this->assertEquals(1, $report['queries_by_type']['SELECT']);
        $this->assertGreaterThan(0, $report['total_time']);
        $this->assertGreaterThan(0, $report['average_time']);

        // Table operations tracking
        $this->assertArrayHasKey('users.INSERT', $report['table_operations']);
        $this->assertEquals(5, $report['table_operations']['users.INSERT']);
    }

    /**
     * Test database assertions.
     * @throws Exception
     */
    public function testDatabaseAssertions(): void
    {
        // Insert test data
        $this->connection->insert('users', [
            'name' => 'Henry',
            'email' => 'henry@example.com',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Assert database has the data
        $this->assertDatabaseHas('users', ['email' => 'henry@example.com']);
        $this->assertDatabaseHas('users', ['name' => 'Henry']);

        // Assert database doesn't have certain data
        $this->assertDatabaseMissing('users', ['email' => 'nonexistent@example.com']);

        // Assert row count
        $this->assertDatabaseCount('users', 1);

        // Insert more data
        $this->connection->insert('users', [
            'name' => 'Iris',
            'email' => 'iris@example.com',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('users', 1, ['name' => 'Henry']);
    }

    /**
     * Example showing how to use fixtures.
     * @throws Exception
     */
    public function testWithFixtures(): void
    {
        // Set fixtures before test runs
        $this->fixtures = [
            'users' => [
                ['name' => 'User 1', 'email' => 'user1@example.com', 'created_at' => date('Y-m-d H:i:s')],
                ['name' => 'User 2', 'email' => 'user2@example.com', 'created_at' => date('Y-m-d H:i:s')],
            ],
        ];

        $this->createTestSchema();
        $this->loadFixtures();

        // Fixtures are now loaded
        $this->assertDatabaseCount('users', 2);
    }
}
