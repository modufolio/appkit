<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Tests\Traits;

use Modufolio\Appkit\Doctrine\Middleware\Debug\DebugMiddleware;
use Modufolio\Appkit\Doctrine\Middleware\Debug\DebugStack;
use Modufolio\Appkit\Doctrine\Middleware\Debug\Query;
use Modufolio\Appkit\Doctrine\OrmConfigurator;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractSQLiteDriver\Middleware\EnableForeignKeys;
use Doctrine\DBAL\Driver\OCI8\Middleware\InitializeSession;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\DefaultSchemaManagerFactory;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

use function array_map;
use function implode;
use function in_array;
use function is_string;
use function str_starts_with;

/**
 * Ultimate DatabaseCase for comprehensive database testing with DBAL and PHPUnit.
 *
 * Features:
 * - Query tracking and analysis
 * - Transaction management
 * - Performance monitoring
 * - Test data fixtures
 * - Query assertions
 * - Database state snapshots
 * - Mock and real connection switching
 */
trait DatabaseTestingCapabilities
{
    private static ?Connection $sharedConnection = null;

    protected ?Connection $connection = null;

    /**
     * Whether the shared connection could be reused by subsequent tests.
     */
    private bool $isConnectionReusable = true;

    // Query counters
    protected int $deleteCounter = 0;
    protected int $insertCounter = 0;
    protected int $updateCounter = 0;
    protected int $selectCounter = 0;
    protected int $totalQueries = 0;

    // Query tracking
    /** @var array<int, array{sql: string, params: ?array, type: string, duration: float, timestamp: float}> */
    protected array $queryLog = [];

    /** @var array<string, int> */
    protected array $tableOperations = [];

    // Performance tracking
    protected float $totalQueryTime = 0.0;
    protected array $slowQueries = [];
    protected float $slowQueryThreshold = 1.0; // seconds

    // Transaction tracking
    protected int $transactionLevel = 0;
    protected bool $inTransaction = false;
    protected array $transactionLog = [];

    // Test data fixtures
    protected array $fixtures = [];
    protected array $cleanupTables = [];

    // Database state
    protected ?array $databaseSnapshot = null;
    protected bool $autoSnapshot = false;

    // Query expectations
    protected array $queryExpectations = [];
    protected bool $strictQueryMode = false;
    public ?DebugStack $debugStack = null;

    /**
     * Set up the test case with enhanced features.
     * @throws Exception
     */
    #[Before]
    protected function setUpDatabase(): void
    {
        $this->connection = $this->getConnection();

        $this->resetTracking();
        $this->fixtures = [];
        if ($this->autoSnapshot) {
            $this->createDatabaseSnapshot();
        }

        $this->loadFixtures();
        $this->syncQueryTracking();
    }

    /**
     * Tear down with cleanup and assertions.
     * @throws Exception
     */
    #[After]
    protected function tearDownDatabase(): void
    {
        if ($this->autoSnapshot) {
            $this->restoreDatabaseSnapshot();
        }

         $this->truncateTables();
    }

    /**
     * @throws Exception
     */
    #[After]
    final protected function disconnect(): void
    {
        while ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        if ($this->isConnectionReusable) {
            return;
        }

        if (self::$sharedConnection !== null) {
            self::$sharedConnection->close();
            self::$sharedConnection = null;
        }

        $this->connection->close();
        unset($this->connection); // @phpstan-ignore unset.possiblyHookedProperty

        $this->isConnectionReusable = true;
    }

    /** Whether the database schema is initialized. */
    private static bool $initialized = false;


    public function getConnection(): Connection
    {
        $configurator = new OrmConfigurator();

        $closure = require dirname(__DIR__, 2) . '/config/test/doctrine.php';
        $closure($configurator);

        $params = $configurator->connectionParams;

        return DriverManager::getConnection(
            $params,
            $this->createConfiguration($params['driver']),
        );
    }

    private function createConfiguration(string $driver): Configuration
    {
        $configuration = new Configuration();

        $this->debugStack = new DebugStack();

        $middlewares = match ($driver) {
            'pdo_oci', 'oci8' => [new InitializeSession(), new DebugMiddleware($this->debugStack)],
            'pdo_sqlite', 'sqlite3' => [new EnableForeignKeys(), new DebugMiddleware($this->debugStack)],
            default => [new DebugMiddleware($this->debugStack)],
        };

        $configuration->setMiddlewares($middlewares);
        $configuration->setSchemaManagerFactory(new DefaultSchemaManagerFactory());

        return $configuration;
    }


    /**
     * Generates a query that will return the given rows without the need to create a temporary table.
     *
     * @param list<string> $columnNames The names of the result columns. Must be non-empty.
     * @param list<list<mixed>> $rows The rows of the result. Each row must have the same number of columns
     *                                as the number of column names.
     */
    public static function generateResultSetQuery(array $columnNames, array $rows, AbstractPlatform $platform): string
    {
        return implode(' UNION ALL ', array_map(static function (array $row) use ($columnNames, $platform): string {
            return $platform->getDummySelectSQL(
                implode(', ', array_map(static function (string $column, $value) use ($platform): string {
                    if (is_string($value)) {
                        $value = $platform->quoteStringLiteral($value);
                    }

                    return $value . ' ' . $platform->quoteSingleIdentifier($column);
                }, $columnNames, $row)),
            );
        }, $rows));
    }

    /**
     * Sync tracking from debugStack queries.
     */
    protected function syncQueryTracking(): void
    {
        if ($this->debugStack === null) {
            return;
        }

        // Reset counters and logs, but preserve expectations
        $this->resetTracking(false);

        // Process all queries from debug stack
        foreach ($this->debugStack->getQueries() as $query) {
            $this->processQuery($query);
        }
    }

    /**
     * Process a single query for tracking.
     */
    protected function processQuery(Query $query): void
    {
        $sql = $query->sql;
        $params = $query->params;
        $executionTime = $query->executionMs;

        // Skip CONNECT queries
        if ($sql === 'CONNECT') {
            return;
        }

        $normalizedSql = strtoupper(trim($sql));
        $type = $this->determineQueryType($normalizedSql);

        // Update counters
        $this->totalQueries++;
        $this->totalQueryTime += $executionTime;

        match ($type) {
            'SELECT' => $this->selectCounter++,
            'INSERT' => $this->insertCounter++,
            'UPDATE' => $this->updateCounter++,
            'DELETE' => $this->deleteCounter++,
            default => null,
        };

        // Track slow queries
        if ($executionTime > $this->slowQueryThreshold) {
            $this->slowQueries[] = [
                'sql' => $sql,
                'params' => $params,
                'duration' => $executionTime,
                'type' => $type,
            ];
        }

        // Add to query log
        $this->queryLog[] = [
            'sql' => $sql,
            'params' => $params,
            'type' => $type,
            'duration' => $executionTime,
            'timestamp' => microtime(true),
        ];

        // Track table operations
        $this->trackTableOperation($sql, $type);
    }

    /**
     * Track operations per table.
     */
    protected function trackTableOperation(string $sql, string $operation): void
    {
        $tables = $this->extractTableNames($sql);
        foreach ($tables as $table) {
            $key = "{$table}.{$operation}";
            $this->tableOperations[$key] = ($this->tableOperations[$key] ?? 0) + 1;
        }
    }

    /**
     * Extract table names from SQL.
     */
    protected function extractTableNames(string $sql): array
    {
        $tables = [];
        $patterns = [
            '/FROM\s+`?(\w+)`?/i',
            '/INTO\s+`?(\w+)`?/i',
            '/UPDATE\s+`?(\w+)`?/i',
            '/DELETE\s+FROM\s+`?(\w+)`?/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $sql, $matches)) {
                $tables = array_merge($tables, $matches[1]);
            }
        }

        return array_unique($tables);
    }

    /**
     * Fixture management.
     * @throws Exception
     */
    protected function loadFixtures(): void
    {
        foreach ($this->fixtures as $table => $data) {
            $this->loadFixtureData($table, $data);
        }
    }

    /**
     * @throws Exception
     */
    protected function loadFixtureData(string $table, array $data): void
    {
        foreach ($data as $row) {
            $this->connection->insert($table, $row);
        }

        $this->cleanupTables[] = $table;
    }

    /**
     * @throws Exception
     */
    protected function truncateTables(): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->connection->executeStatement('PRAGMA foreign_keys = OFF');
        foreach (array_reverse($this->cleanupTables) as $table) {
            $this->connection->executeStatement($platform->getTruncateTableSQL($table));
        }
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');
    }

    /**
     * Database snapshot functionality.
     * @throws Exception
     */
    protected function createDatabaseSnapshot(): void
    {
        $this->databaseSnapshot = [];
        $tables = $this->connection->createSchemaManager()->listTableNames();

        foreach ($tables as $table) {
            $this->databaseSnapshot[$table] = $this->connection
                ->executeQuery("SELECT * FROM {$table}")
                ->fetchAllAssociative();
        }
    }

    /**
     * @throws Exception
     */
    protected function restoreDatabaseSnapshot(): void
    {
        if (!$this->databaseSnapshot) {
            return;
        }

        // Disable foreign key checks
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($this->databaseSnapshot as $table => $data) {
            $this->connection->executeStatement("TRUNCATE TABLE {$table}");
            foreach ($data as $row) {
                $this->connection->insert($table, $row);
            }
        }

        // Re-enable foreign key checks
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }


    /**
     * Get all queries from debug stack.
     * @return Query[]
     */
    public function getDebugQueries(): array
    {
        return $this->debugStack?->getQueries() ?? [];
    }

    /**
     * Get query count directly from debug stack (excluding CONNECT).
     */
    public function getDebugQueryCount(): int
    {
        $queries = $this->getDebugQueries();
        return count(array_filter($queries, fn (Query $q) => $q->sql !== 'CONNECT'));
    }

    /**
     * Get total execution time from debug stack.
     */
    public function getDebugTotalExecutionTime(): float
    {
        $queries = $this->getDebugQueries();
        $total = 0.0;
        foreach ($queries as $query) {
            if ($query->sql !== 'CONNECT') {
                $total += $query->executionMs;
            }
        }
        return $total;
    }

    /**
     * Find queries matching a pattern.
     * @return Query[]
     */
    public function findDebugQueries(string $pattern): array
    {
        $queries = $this->getDebugQueries();
        return array_filter($queries, fn (Query $q) => preg_match($pattern, $q->sql));
    }

    /**
     * Get the last executed query.
     */
    public function getLastDebugQuery(): ?Query
    {
        $queries = $this->getDebugQueries();
        if (empty($queries)) {
            return null;
        }
        return $queries[array_key_last($queries)];
    }

    /**
     * Assertion helpers.
     */
    public function assertQueryCount(int $expected, ?string $type = null): void
    {
        // Sync before asserting
        $this->syncQueryTracking();

        $actual = $type ? $this->getQueryCountByType($type) : $this->totalQueries;
        $typeLabel = $type ? " {$type}" : '';

        $this->assertEquals(
            $expected,
            $actual,
            "Expected {$expected}{$typeLabel} queries but {$actual} were executed"
        );
    }

    public function assertNoSlowQueries(): void
    {
        $this->syncQueryTracking();

        $this->assertEmpty(
            $this->slowQueries,
            sprintf(
                'Found %d slow queries (threshold: %fs): %s',
                count($this->slowQueries),
                $this->slowQueryThreshold,
                json_encode($this->slowQueries, JSON_PRETTY_PRINT)
            )
        );
    }

    public function assertTableNotQueried(string $table): void
    {
        $this->syncQueryTracking();

        foreach ($this->queryLog as $query) {
            $tables = $this->extractTableNames($query['sql']);
            $this->assertNotContains(
                $table,
                $tables,
                "Table '{$table}' was unexpectedly queried: {$query['sql']}"
            );
        }
    }

    /**
     * Assert that a specific table WAS queried.
     */
    public function assertTableQueried(string $table, ?string $operation = null): void
    {
        $this->syncQueryTracking();

        $found = false;
        foreach ($this->queryLog as $query) {
            $tables = $this->extractTableNames($query['sql']);
            if (in_array($table, $tables)) {
                if ($operation === null || $query['type'] === strtoupper($operation)) {
                    $found = true;
                    break;
                }
            }
        }

        $operationMsg = $operation ? " with {$operation} operation" : '';
        $this->assertTrue(
            $found,
            "Table '{$table}'{$operationMsg} was not queried"
        );
    }

    /**
     * Assert that a specific query pattern was executed.
     */
    public function assertQueryExecuted(string $pattern, ?int $times = null): void
    {
        $this->syncQueryTracking();

        $matchingQueries = array_filter(
            $this->queryLog,
            fn ($query) => preg_match($pattern, $query['sql'])
        );

        if ($times !== null) {
            $this->assertCount(
                $times,
                $matchingQueries,
                sprintf(
                    "Expected query pattern '%s' to be executed %d times, but it was executed %d times",
                    $pattern,
                    $times,
                    count($matchingQueries)
                )
            );
        } else {
            $this->assertNotEmpty(
                $matchingQueries,
                sprintf("Expected query pattern '%s' to be executed at least once", $pattern)
            );
        }
    }

    /**
     * Assert that a specific query pattern was NOT executed.
     */
    public function assertQueryNotExecuted(string $pattern): void
    {
        $this->syncQueryTracking();

        $matchingQueries = array_filter(
            $this->queryLog,
            fn ($query) => preg_match($pattern, $query['sql'])
        );

        $this->assertEmpty(
            $matchingQueries,
            sprintf(
                "Expected query pattern '%s' to NOT be executed, but found %d occurrences",
                $pattern,
                count($matchingQueries)
            )
        );
    }

    /**
     * Assert query execution time is below threshold.
     */
    public function assertQueryPerformance(string $pattern, float $maxSeconds): void
    {
        $this->syncQueryTracking();

        $matchingQueries = array_filter(
            $this->queryLog,
            fn ($query) => preg_match($pattern, $query['sql'])
        );

        foreach ($matchingQueries as $query) {
            $this->assertLessThanOrEqual(
                $maxSeconds,
                $query['duration'],
                sprintf(
                    "Query '%s' took %.4fs, which exceeds the maximum of %.4fs",
                    $query['sql'],
                    $query['duration'],
                    $maxSeconds
                )
            );
        }
    }

    public function assertTransactionCommitted(): void
    {
        $this->assertFalse(
            $this->inTransaction,
            'Transaction was not properly committed'
        );

        $commits = array_filter($this->transactionLog, fn ($log) => $log['action'] === 'commit');
        $this->assertNotEmpty($commits, 'No commit found in transaction log');
    }

    public function assertTransactionRolledBack(): void
    {
        $this->assertFalse(
            $this->inTransaction,
            'Transaction was not properly rolled back'
        );

        $rollbacks = array_filter($this->transactionLog, fn ($log) => $log['action'] === 'rollback');
        $this->assertNotEmpty($rollbacks, 'No rollback found in transaction log');
    }

    /**
     * Utility methods.
     */
    protected function resetTracking(bool $resetExpectations = true): void
    {
        $this->deleteCounter = 0;
        $this->insertCounter = 0;
        $this->updateCounter = 0;
        $this->selectCounter = 0;
        $this->totalQueries = 0;
        $this->queryLog = [];
        $this->tableOperations = [];
        $this->totalQueryTime = 0.0;
        $this->slowQueries = [];
        $this->transactionLevel = 0;
        $this->inTransaction = false;
        $this->transactionLog = [];
        if ($resetExpectations) {
            $this->queryExpectations = [];
        }
    }

    protected function determineQueryType(string $normalizedSql): string
    {
        $types = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP', 'TRUNCATE'];
        foreach ($types as $type) {
            if (str_starts_with($normalizedSql, $type)) {
                return $type;
            }
        }
        return 'OTHER';
    }

    protected function getQueryCountByType(string $type): int
    {
        return match (strtoupper($type)) {
            'SELECT' => $this->selectCounter,
            'INSERT' => $this->insertCounter,
            'UPDATE' => $this->updateCounter,
            'DELETE' => $this->deleteCounter,
            default => 0,
        };
    }

    /**
     * Performance analysis.
     */
    public function getPerformanceReport(): array
    {
        $this->syncQueryTracking();

        return [
            'total_queries' => $this->totalQueries,
            'total_time' => $this->totalQueryTime,
            'average_time' => $this->totalQueries > 0 ? $this->totalQueryTime / $this->totalQueries : 0,
            'slow_queries' => count($this->slowQueries),
            'queries_by_type' => [
                'SELECT' => $this->selectCounter,
                'INSERT' => $this->insertCounter,
                'UPDATE' => $this->updateCounter,
                'DELETE' => $this->deleteCounter,
            ],
            'table_operations' => $this->tableOperations,
        ];
    }

    /**
     * Get query log with optional filtering.
     */
    public function getQueryLog(?string $type = null, ?string $table = null): array
    {
        $this->syncQueryTracking();

        $log = $this->queryLog;

        if ($type) {
            $log = array_filter($log, fn ($entry) => $entry['type'] === strtoupper($type));
        }

        if ($table) {
            $log = array_filter($log, function ($entry) use ($table) {
                $tables = $this->extractTableNames($entry['sql']);
                return in_array($table, $tables);
            });
        }

        return array_values($log);
    }


    public function dumpQueryLog(): void
    {
        $this->syncQueryTracking();
        echo json_encode($this->queryLog, JSON_PRETTY_PRINT) . "\n";
    }


    /**
     * Set test fixtures.
     */
    public function withFixtures(array $fixtures): self
    {
        $this->fixtures = $fixtures;
        return $this;
    }

    /**
     * Enable automatic snapshots.
     */
    public function withAutoSnapshot(): self
    {
        $this->autoSnapshot = true;
        return $this;
    }

    /**
     * Set slow query threshold.
     */
    public function setSlowQueryThreshold(float $seconds): self
    {
        $this->slowQueryThreshold = $seconds;
        return $this;
    }

    /**
     * Enable strict query validation mode.
     */
    public function enableStrictMode(): self
    {
        $this->strictQueryMode = true;
        return $this;
    }

    /**
     * Create test database schema.
     * @throws Exception
     */
    protected function createTestSchema(): void
    {
        $schema = $this->getTestSchema();
        $platform = $this->connection->getDatabasePlatform();
        $schemaManager = $this->connection->createSchemaManager();

        // Get the list of tables defined in the schema
        $schemaTables = $schema->getTables();
        $existingTables = $schemaManager->listTableNames();

        // Check if any of the schema's tables already exist
        $tablesExist = false;
        foreach ($schemaTables as $table) {
            if (in_array($table->getName(), $existingTables)) {
                $tablesExist = true;
                break;
            }
        }

        // If no tables exist, execute the schema creation queries
        if (!$tablesExist) {
            $queries = $schema->toSql($platform);
            foreach ($queries as $query) {
                $this->connection->executeStatement($query);
            }
        }
    }

    abstract public function getTestSchema(): Schema;


    /**
     * Assert database state matches expected data.
     * @throws Exception
     */
    protected function assertDatabaseHas(string $table, array $criteria): void
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select('COUNT(*)')
            ->from($table);

        foreach ($criteria as $column => $value) {
            $qb->andWhere("{$column} = :{$column}")
                ->setParameter($column, $value);
        }

        $count = (int)$qb->executeQuery()->fetchOne();

        $this->assertGreaterThan(
            0,
            $count,
            sprintf(
                'Failed asserting that table [%s] has row matching: %s',
                $table,
                json_encode($criteria)
            )
        );
    }

    /**
     * Assert database state does not match criteria.
     * @throws Exception
     */
    protected function assertDatabaseMissing(string $table, array $criteria): void
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select('COUNT(*)')
            ->from($table);

        foreach ($criteria as $column => $value) {
            $qb->andWhere("{$column} = :{$column}")
                ->setParameter($column, $value);
        }

        $count = (int)$qb->executeQuery()->fetchOne();

        $this->assertEquals(
            0,
            $count,
            sprintf(
                'Failed asserting that table [%s] does not have row matching: %s',
                $table,
                json_encode($criteria)
            )
        );
    }

    /**
     * Assert exact row count in table.
     */
    protected function assertDatabaseCount(string $table, int $expected, ?array $criteria = null): void
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select('COUNT(*)')
            ->from($table);

        if ($criteria) {
            foreach ($criteria as $column => $value) {
                $qb->andWhere("{$column} = :{$column}")
                    ->setParameter($column, $value);
            }
        }

        $count = (int)$qb->executeQuery()->fetchOne();

        $this->assertEquals(
            $expected,
            $count,
            sprintf(
                'Failed asserting that table [%s] has %d rows%s. Found: %d',
                $table,
                $expected,
                $criteria ? ' matching criteria: ' . json_encode($criteria) : '',
                $count
            )
        );
    }

    /**
     * Seed database with custom data.
     * @throws Exception
     */
    protected function seed(string $table, array $data): void
    {
        foreach ($data as $row) {
            $this->connection->insert($table, $row);
        }
    }

    /**
     * Truncate specific tables.
     * @throws Exception
     */
    protected function truncate(string ...$tables): void
    {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($tables as $table) {
            $this->connection->executeStatement("TRUNCATE TABLE {$table}");
        }

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Execute raw SQL for testing.
     * @throws Exception
     */
    protected function executeSql(string $sql, array $params = []): void
    {
        $this->connection->executeStatement($sql, $params);
    }

    /**
     * Fetch data for assertions.
     * @throws Exception
     */
    protected function fetchFromDatabase(string $table, array $criteria = []): ?array
    {

        $qb = $this->connection->createQueryBuilder();
        $qb->select('*')
            ->from($table);

        foreach ($criteria as $column => $value) {
            $qb->andWhere("{$column} = :{$column}")
                ->setParameter($column, $value);
        }

        return $qb->executeQuery()->fetchAssociative() ?: null;
    }

    /**
     * Drop test database schema.
     * @throws Exception
     */
    protected function dropTestSchema(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $schema = $schemaManager->introspectSchema();

        // Drop all tables in reverse order to handle foreign keys
        $tables = $schema->getTables();

        // Disable foreign key checks
        //$this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        foreach (array_reverse($tables) as $table) {
            $this->connection->executeStatement("DROP TABLE IF EXISTS {$table->getObjectName()->toString()}");
        }

        // Re-enable foreign key checks
        //$this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

}
