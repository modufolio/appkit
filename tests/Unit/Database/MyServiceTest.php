<?php

namespace Modufolio\Appkit\Tests\Unit\Database;

use Doctrine\DBAL\Exception;
use Modufolio\Appkit\Tests\Traits\DatabaseTestConfiguration;
use Modufolio\Appkit\Testing\DatabaseTestingCapabilities;
use PHPUnit\Framework\TestCase;

class MyServiceTest extends TestCase
{
    use DatabaseTestConfiguration;
    use DatabaseTestingCapabilities;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestSchema();

        // Configure test environment
        $this->withAutoSnapshot()          // Auto snapshot/restore
            ->setSlowQueryThreshold(0.5)   // 500ms slow query threshold
            ->withFixtures([                // Load test data
                'users' => [
                    // No explicit id: it is an identity column, and SQL Server
                    // refuses explicit values for those.
                    ['name' => 'John', 'email' => 'john@example.com'],
                ],
            ]);
    }

    /**
     * @throws Exception
     */
    public function testDatabaseOperation(): void
    {
        // Execute your code
        $this->connection()->insert('users', [
            'name' => 'John',
            'email' => 'john@example.com',
            'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);

        // Assertions
        $this->assertQueryCount(1, 'INSERT');
        $this->assertNoSlowQueries();
    }

    /**
     * @throws Exception
     */
    public function testTransactionAssertionsTrackCommits(): void
    {
        $this->connection()->beginTransaction();
        $this->connection()->insert('users', [
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);
        $this->connection()->commit();

        $this->syncQueryTracking();
        $this->assertTransactionCommitted();
    }

    /**
     * @throws Exception
     */
    public function testTransactionAssertionsTrackRollbacks(): void
    {
        $this->connection()->beginTransaction();
        $this->connection()->insert('users', [
            'name' => 'Joe',
            'email' => 'joe@example.com',
            'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);
        $this->connection()->rollBack();

        $this->syncQueryTracking();
        $this->assertTransactionRolledBack();
        $this->assertDatabaseMissing('users', ['email' => 'joe@example.com']);
    }
}
