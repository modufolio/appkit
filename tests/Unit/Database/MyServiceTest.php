<?php

namespace Modufolio\Appkit\Tests\Unit\Database;

use Modufolio\Appkit\Tests\Traits\DatabaseTestConfiguration;
use Modufolio\Appkit\Tests\Traits\DatabaseTestingCapabilities;
use Doctrine\DBAL\Exception;
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
            ->enableStrictMode()            // Enable strict validations
            ->withFixtures([                // Load test data
                'users' => [
                    ['id' => 1, 'name' => 'John', 'email' => 'john@example.com']
                ]
            ]);
    }

    /**
     * @throws Exception
     */
    public function testDatabaseOperation(): void
    {

        // Execute your code
        $this->connection->insert('users', [
            'id' => 1,
            'name' => 'John',
            'email' => 'john@example.com',
            'created_at' => (new \DateTime())->format('Y-m-d H:i:s')
        ]);



        // Assertions
        $this->assertQueryCount(1, 'INSERT');
        $this->assertNoSlowQueries();
      
    }
}
