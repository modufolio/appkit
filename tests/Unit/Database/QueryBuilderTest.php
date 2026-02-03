<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Database;


use Modufolio\Appkit\Tests\Traits\DatabaseTestingCapabilities;
use Modufolio\Appkit\Doctrine\QueryBuilder;

use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;

class QueryBuilderTest extends TestCase
{
    use DatabaseTestingCapabilities;


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
     * Define the test schema
     */
    public function getTestSchema(): Schema
    {
        $schema = new Schema();

        // Users table
        $users = $schema->createTable('users');
        $users->addColumn('id', 'integer', ['autoincrement' => true]);
        $users->addColumn('name', 'string', ['length' => 255]);
        $users->addColumn('email', 'string', ['length' => 255]);
        $users->addColumn('status', 'string', ['length' => 50, 'default' => 'active']);
        $users->addColumn('age', 'integer', ['notnull' => false]);
        $users->addColumn('created_at', 'datetime');
        $users->setPrimaryKey(['id']);
        $users->addUniqueIndex(['email']);

        // Posts table
        $posts = $schema->createTable('posts');
        $posts->addColumn('id', 'integer', ['autoincrement' => true]);
        $posts->addColumn('user_id', 'integer');
        $posts->addColumn('title', 'string', ['length' => 255]);
        $posts->addColumn('content', 'text');
        $posts->addColumn('views', 'integer', ['default' => 0]);
        $posts->addColumn('published', 'boolean', ['default' => false]);
        $posts->addColumn('created_at', 'datetime');
        $posts->setPrimaryKey(['id']);
        $posts->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);

        return $schema;
    }

    // ═══════════════════════════════════════════════════════════════════════════════
    // SELECT Tests
    // ═══════════════════════════════════════════════════════════════════════════════

    public function testSelectAll(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $results = $qb->from('users')->select()->get();

        // Assert
        $this->assertCount(3, $results);
        $this->assertQueryCount(1, 'SELECT');
        $this->assertTableQueried('users', 'SELECT');
    }

    public function testSelectSpecificColumns(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $results = $qb->from('users')->select('id', 'name', 'email')->get();

        // Assert
        $this->assertCount(3, $results);
        $this->assertArrayHasKey('name', $results[0]);
        $this->assertArrayHasKey('email', $results[0]);
        $this->assertQueryExecuted('/SELECT.*id.*name.*email.*FROM users/i');
    }

    public function testSelectWithAlias(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $results = $qb->from('users')->select(['name' => 'user_name'], 'email')->get();

        // Assert
        $this->assertArrayHasKey('user_name', $results[0]);
        $this->assertArrayHasKey('email', $results[0]);
    }

    public function testSelectRaw(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $results = $qb->from('users')
            ->selectRaw('COUNT(*) as total')
            ->get();

        // Assert
        $this->assertEquals(3, $results[0]['total']);
    }

    // ═══════════════════════════════════════════════════════════════════════════════
    // WHERE Tests
    // ═══════════════════════════════════════════════════════════════════════════════

    public function testWhereEquals(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $results = $qb->from('users')
            ->select()
            ->where('email', '=', 'john@example.com')
            ->get();

        // Assert
        $this->assertCount(1, $results);
        $this->assertEquals('John Doe', $results[0]['name']);
    }

    public function testWhereGreaterThan(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $results = $qb->from('users')
            ->select()
            ->where('age', '>', 25)
            ->get();

        // Assert
        $this->assertCount(2, $results);
    }

    public function testOrWhere(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $results = $qb->from('users')
            ->select()
            ->where('name', '=', 'John Doe')
            ->orWhere('name', '=', 'Jane Smith')
            ->get();

        // Assert
        $this->assertCount(2, $results);
    }

    public function testWhereIn(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $results = $qb->from('users')
            ->select()
            ->whereIn('name', ['John Doe', 'Jane Smith'])
            ->get();

        // Assert
        $this->assertCount(2, $results);
    }

    public function testWhereNotIn(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $results = $qb->from('users')
            ->select()
            ->whereNotIn('name', ['John Doe'])
            ->get();

        // Assert
        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertNotEquals('John Doe', $result['name']);
        }
    }

    public function testWhereNull(): void
    {
        // Arrange
        $this->seed('users', [
            ['name' => 'Test User', 'email' => 'test@example.com', 'status' => 'active', 'age' => null, 'created_at' => date('Y-m-d H:i:s')],
        ]);
        $qb = new QueryBuilder($this->connection);

        // Act
        $results = $qb->from('users')
            ->select()
            ->whereNull('age')
            ->get();

        // Assert
        $this->assertCount(1, $results);
    }

    public function testWhereNotNull(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $results = $qb->from('users')
            ->select()
            ->whereNotNull('age')
            ->get();

        // Assert
        $this->assertCount(3, $results);
    }

    public function testWhereExpression(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $results = $qb->from('users')
            ->select()
            ->whereExpression(fn($expr) => $expr->or(
                $expr->eq('name', $expr->literal('John Doe')),
                $expr->eq('name', $expr->literal('Jane Smith'))
            ))
            ->get();

        // Assert
        $this->assertCount(2, $results);
    }

    public function testWhereRaw(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $results = $qb->from('users')
            ->select()
            ->whereRaw('age > 25')
            ->get();

        // Assert
        $this->assertCount(2, $results);
    }

    // ═══════════════════════════════════════════════════════════════════════════════
    // JOIN Tests
    // ═══════════════════════════════════════════════════════════════════════════════

    public function testInnerJoin(): void
    {
        // Arrange
        $this->seedUsersAndPosts();
        $qb = new QueryBuilder($this->connection);

        // Act
        $results = $qb->from('users', 'u')
            ->select('u.name', 'p.title')
            ->join('posts', 'u.id', '=', 'p.user_id', 'p')
            ->get();

        // Assert
        $this->assertCount(2, $results);
        $this->assertArrayHasKey('name', $results[0]);
        $this->assertArrayHasKey('title', $results[0]);
    }

    public function testLeftJoin(): void
    {
        // Arrange
        $this->seedUsersAndPosts();
        $qb = new QueryBuilder($this->connection);

        // Act
        $results = $qb->from('users', 'u')
            ->select('u.name', 'p.title')
            ->leftJoin('posts', 'u.id', '=', 'p.user_id', 'p')
            ->get();

        // Assert
        $this->assertGreaterThanOrEqual(3, count($results)); // At least all users
    }

    // ═══════════════════════════════════════════════════════════════════════════════
    // ORDER BY, GROUP BY, LIMIT Tests
    // ═══════════════════════════════════════════════════════════════════════════════

    public function testOrderBy(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $results = $qb->from('users')
            ->select('name')
            ->orderBy('name', 'ASC')
            ->get();

        // Assert
        $this->assertEquals('Bob Wilson', $results[0]['name']);
        $this->assertEquals('John Doe', $results[2]['name']);
    }

    public function testOrderByDesc(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $results = $qb->from('users')
            ->select('name')
            ->orderBy('name', 'DESC')
            ->get();

        // Assert
        $this->assertEquals('John Doe', $results[0]['name']);
    }

    public function testLimit(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $results = $qb->from('users')
            ->select()
            ->limit(2)
            ->get();

        // Assert
        $this->assertCount(2, $results);
    }

    public function testOffset(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $results = $qb->from('users')
            ->select()
            ->orderBy('id', 'ASC')
            ->offset(1)
            ->limit(2)
            ->get();

        // Assert
        $this->assertCount(2, $results);
    }

    public function testGroupBy(): void
    {
        // Arrange
        $this->seedUsersAndPosts();
        $qb = new QueryBuilder($this->connection);

        // Act
        $results = $qb->from('posts', 'p')
            ->selectRaw('user_id, COUNT(*) as post_count')
            ->groupBy('user_id')
            ->get();

        // Assert
        $this->assertGreaterThan(0, count($results));
    }

    // ═══════════════════════════════════════════════════════════════════════════════
    // INSERT Tests
    // ═══════════════════════════════════════════════════════════════════════════════

    public function testInsert(): void
    {
        // Arrange
        $qb = new QueryBuilder($this->connection);

        // Act
        $affectedRows = $qb->from('users')->insert([
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'status' => 'active',
            'age' => 25,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Assert
        $this->assertEquals(1, $affectedRows);
        $this->assertDatabaseHas('users', ['email' => 'newuser@example.com']);
        $this->assertQueryCount(1, 'INSERT');
    }

    public function testMultipleInserts(): void
    {
        // Arrange
        $qb1 = new QueryBuilder($this->connection);
        $qb2 = new QueryBuilder($this->connection);

        // Act
        $qb1->from('users')->insert([
            'name' => 'User 1',
            'email' => 'user1@example.com',
            'status' => 'active',
            'age' => 25,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $qb2->from('users')->insert([
            'name' => 'User 2',
            'email' => 'user2@example.com',
            'status' => 'active',
            'age' => 30,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Assert
        $this->assertDatabaseCount('users', 2);
        $this->assertQueryCount(2, 'INSERT');
    }

    // ═══════════════════════════════════════════════════════════════════════════════
    // UPDATE Tests
    // ═══════════════════════════════════════════════════════════════════════════════

    public function testUpdate(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $affectedRows = $qb->from('users')
            ->where('email', '=', 'john@example.com')
            ->update(['status' => 'inactive']);

        // Assert
        $this->assertEquals(1, $affectedRows);
        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com',
            'status' => 'inactive'
        ]);
        $this->assertQueryCount(1, 'UPDATE');
    }

    public function testUpdateMultipleRows(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $affectedRows = $qb->from('users')
            ->where('age', '>', 25)
            ->update(['status' => 'senior']);

        // Assert
        $this->assertEquals(2, $affectedRows);
        $this->assertQueryCount(1, 'UPDATE');
    }

    // ═══════════════════════════════════════════════════════════════════════════════
    // DELETE Tests
    // ═══════════════════════════════════════════════════════════════════════════════

    public function testDelete(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $affectedRows = $qb->from('users')
            ->where('email', '=', 'john@example.com')
            ->delete();

        // Assert
        $this->assertEquals(1, $affectedRows);
        $this->assertDatabaseMissing('users', ['email' => 'john@example.com']);
        $this->assertDatabaseCount('users', 2);
        $this->assertQueryCount(1, 'DELETE');
    }

    public function testDeleteMultipleRows(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $affectedRows = $qb->from('users')
            ->where('age', '>', 25)
            ->delete();

        // Assert
        $this->assertEquals(2, $affectedRows);
        $this->assertDatabaseCount('users', 1);
    }

    // ═══════════════════════════════════════════════════════════════════════════════
    // Fetching Methods Tests
    // ═══════════════════════════════════════════════════════════════════════════════

    public function testGet(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $results = $qb->from('users')->select()->get();

        // Assert
        $this->assertIsArray($results);
        $this->assertCount(3, $results);
    }

    public function testFirst(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $result = $qb->from('users')
            ->select()
            ->where('email', '=', 'john@example.com')
            ->first();

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals('John Doe', $result['name']);
    }

    public function testFirstReturnsNullWhenNoResults(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $result = $qb->from('users')
            ->select()
            ->where('email', '=', 'nonexistent@example.com')
            ->first();

        // Assert
        $this->assertNull($result);
    }

    public function testCount(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $count = $qb->from('users')->count();

        // Assert
        $this->assertEquals(3, $count);
    }

    public function testCountWithWhere(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $count = $qb->from('users')
            ->where('age', '>', 25)
            ->count();

        // Assert
        $this->assertEquals(2, $count);
    }

    public function testFetchColumn(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $emails = $qb->from('users')
            ->select('email')
            ->orderBy('email', 'ASC')
            ->fetchColumn('email');

        // Assert
        $this->assertIsArray($emails);
        $this->assertCount(3, $emails);
        $this->assertContains('john@example.com', $emails);
    }

    // ═══════════════════════════════════════════════════════════════════════════════
    // Complex Query Tests
    // ═══════════════════════════════════════════════════════════════════════════════

    public function testComplexQueryWithMultipleConditions(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $results = $qb->from('users')
            ->select('name', 'email', 'age')
            ->where('status', '=', 'active')
            ->where('age', '>=', 25)
            ->orderBy('age', 'DESC')
            ->limit(2)
            ->get();

        // Assert
        $this->assertCount(2, $results);
        $this->assertGreaterThanOrEqual(25, $results[0]['age']);
    }

    public function testChainedMethodCalls(): void
    {
        // Arrange
        $this->seedUsersAndPosts();
        $qb = new QueryBuilder($this->connection);

        // Act
        $results = $qb->from('users', 'u')
            ->select('u.name', 'p.title', 'p.views')
            ->join('posts', 'u.id', '=', 'p.user_id', 'p')
            ->where('p.published', '=', true)
            ->orderBy('p.views', 'DESC')
            ->limit(5)
            ->get();

        // Assert
        $this->assertIsArray($results);
    }

    // ═══════════════════════════════════════════════════════════════════════════════
    // Utility Methods Tests
    // ═══════════════════════════════════════════════════════════════════════════════

    public function testToSql(): void
    {
        // Arrange
        $qb = new QueryBuilder($this->connection);

        // Act
        $sql = $qb->from('users')
            ->select('name', 'email')
            ->where('status', '=', 'active')
            ->toSql();

        // Assert
        $this->assertIsString($sql);
        $this->assertStringContainsString('SELECT', $sql);
        $this->assertStringContainsString('FROM users', $sql);
        $this->assertStringContainsString('WHERE', $sql);
    }

    public function testGetQueryBuilder(): void
    {
        // Arrange
        $qb = new QueryBuilder($this->connection);

        // Act
        $dbalQb = $qb->getQueryBuilder();

        // Assert
        $this->assertInstanceOf(\Doctrine\DBAL\Query\QueryBuilder::class, $dbalQb);
    }

    public function testExpr(): void
    {
        // Arrange
        $qb = new QueryBuilder($this->connection);

        // Act
        $expr = $qb->expr();

        // Assert
        $this->assertInstanceOf(\Doctrine\DBAL\Query\Expression\ExpressionBuilder::class, $expr);
    }

    // ═══════════════════════════════════════════════════════════════════════════════
    // Performance Tests
    // ═══════════════════════════════════════════════════════════════════════════════

    public function testQueryPerformance(): void
    {
        // Arrange
        $this->seedUsers();
        $this->setSlowQueryThreshold(0.1); // 100ms threshold
        $qb = new QueryBuilder($this->connection);

        // Act
        $qb->from('users')->select()->get();

        // Assert
        $this->assertNoSlowQueries();
    }

    public function testPerformanceReport(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $qb->from('users')->select()->get();
        $qb->from('users')->where('age', '>', 25)->count();

        // Assert
        $report = $this->getPerformanceReport();
        $this->assertArrayHasKey('total_queries', $report);
        $this->assertArrayHasKey('total_time', $report);
        $this->assertArrayHasKey('queries_by_type', $report);
        $this->assertGreaterThan(0, $report['total_queries']);
    }

    // ═══════════════════════════════════════════════════════════════════════════════
    // Edge Cases and Error Handling
    // ═══════════════════════════════════════════════════════════════════════════════

    public function testInvalidOrderDirection(): void
    {
        // Arrange
        $qb = new QueryBuilder($this->connection);

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid order direction: INVALID');

        // Act
        $qb->from('users')->orderBy('name', 'INVALID');
    }

    public function testEmptyWhereIn(): void
    {
        // Arrange
        $this->seedUsers();
        $qb = new QueryBuilder($this->connection);

        // Act
        $results = $qb->from('users')
            ->select()
            ->whereIn('name', [])
            ->get();

        // Assert - should return no results or all results depending on DB behavior
        $this->assertIsArray($results);
    }

    // ═══════════════════════════════════════════════════════════════════════════════
    // Helper Methods
    // ═══════════════════════════════════════════════════════════════════════════════

    private function seedUsers(): void
    {
        $this->seed('users', [
            [
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'status' => 'active',
                'age' => 30,
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Jane Smith',
                'email' => 'jane@example.com',
                'status' => 'active',
                'age' => 28,
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Bob Wilson',
                'email' => 'bob@example.com',
                'status' => 'active',
                'age' => 22,
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ]);
    }

    private function seedUsersAndPosts(): void
    {
        $this->seedUsers();

        // Get user IDs
        $users = $this->connection->createQueryBuilder()
            ->select('id', 'email')
            ->from('users')
            ->executeQuery()
            ->fetchAllAssociative();

        $userIdByEmail = array_column($users, 'id', 'email');

        $this->seed('posts', [
            [
                'user_id' => $userIdByEmail['john@example.com'],
                'title' => 'First Post',
                'content' => 'This is my first post',
                'views' => 100,
                'published' => true,
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'user_id' => $userIdByEmail['jane@example.com'],
                'title' => 'Second Post',
                'content' => 'This is another post',
                'views' => 50,
                'published' => true,
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ]);
    }
}