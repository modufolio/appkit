<?php

namespace Modufolio\Appkit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Psr\Log\LoggerInterface;

class ConnectionOptimizer
{
    public function __construct(
        private readonly bool $isDevelopment = false,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /**
     * @throws Exception
     */
    public function optimize(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();

        try {
            match (true) {
                $platform instanceof SqlitePlatform => $this->optimizeSqlite($connection),
                $platform instanceof MySQLPlatform => $this->optimizeMySQL($connection),
                $platform instanceof PostgreSQLPlatform => $this->optimizePostgreSQL($connection),
                $platform instanceof SQLServerPlatform => $this->optimizeSQLServer($connection),
                default => null,
            };

            $this->logger?->debug('Database connection optimized', [
                'platform' => $platform::class,
            ]);
        } catch (\Throwable $e) {
            // Log but don't fail - optimizations are nice-to-have
            $this->logger?->warning('Database optimization failed', [
                'platform' => $platform::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @throws Exception
     */
    private function optimizeSqlite(Connection $connection): void
    {
        $connection->executeStatement('PRAGMA journal_mode = WAL;');
        $connection->executeStatement('PRAGMA synchronous = ' . ($this->isDevelopment ? 'FULL' : 'NORMAL') . ';');
        $connection->executeStatement('PRAGMA foreign_keys = ON;');

        $cacheSize = $this->isDevelopment ? -32000 : -64000; // 32MB dev, 64MB prod
        $connection->executeStatement("PRAGMA cache_size = {$cacheSize};");

        $connection->executeStatement('PRAGMA temp_store = MEMORY;');
        $connection->executeStatement('PRAGMA mmap_size = 30000000000;');
        $connection->executeStatement('PRAGMA page_size = 4096;');
        $connection->executeStatement('PRAGMA auto_vacuum = INCREMENTAL;');
    }

    /**
     * @throws Exception
     */
    private function optimizeMySQL(Connection $connection): void
    {
        $connection->executeStatement("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';");
        $connection->executeStatement("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;");
        $connection->executeStatement("SET SESSION innodb_strict_mode = ON;");
        $connection->executeStatement("SET SESSION transaction_isolation = 'READ-COMMITTED';");
    }

    /**
     * @throws Exception
     */
    private function optimizePostgreSQL(Connection $connection): void
    {
        $connection->executeStatement("SET work_mem = '64MB';");
        $connection->executeStatement("SET random_page_cost = 1.1;");
        $connection->executeStatement("SET effective_cache_size = '4GB';");
        $connection->executeStatement("SET timezone = 'UTC';");

        // Only enable JIT in production
        if (!$this->isDevelopment) {
            $connection->executeStatement("SET jit = on;");
        }
    }

    /**
     * @throws Exception
     */
    private function optimizeSQLServer(Connection $connection): void
    {
        $connection->executeStatement("SET TRANSACTION ISOLATION LEVEL READ COMMITTED;");
        $connection->executeStatement("SET NOCOUNT ON;");
        $connection->executeStatement("SET ARITHABORT ON;");
        $connection->executeStatement("SET ARITHIGNORE OFF;");
        $connection->executeStatement("SET QUOTED_IDENTIFIER ON;");
        $connection->executeStatement("SET CONCAT_NULL_YIELDS_NULL ON;");
        $connection->executeStatement("SET DATEFORMAT ymd;");
        $connection->executeStatement("SET LANGUAGE us_english;");
        $connection->executeStatement("SET IMPLICIT_TRANSACTIONS OFF;");
        $connection->executeStatement("SET XACT_ABORT ON;");

        if ($this->isDevelopment) {
            $connection->executeStatement("SET STATISTICS TIME ON;");
            $connection->executeStatement("SET STATISTICS IO ON;");
        } else {
            $connection->executeStatement("SET STATISTICS TIME OFF;");
            $connection->executeStatement("SET STATISTICS IO OFF;");
        }
    }
}