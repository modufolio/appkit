<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Doctrine\Middleware\Debug;

/**
 * Debug stack for tracking SQL queries with bounded memory usage.
 *
 * Memory Leak Prevention:
 * - Implements a circular buffer to limit query history
 * - Prevents unbounded memory growth in long-running RoadRunner workers
 * - Default limit of 100 queries provides sufficient debugging context
 */
final class DebugStack
{
    /** @var Query[] */
    private array $queries = [];

    /**
     * Maximum number of queries to keep in memory.
     * Prevents memory leaks in long-running workers.
     */
    private int $maxQueries = 100;

    public function append(Query $query): void
    {
        $this->queries[] = $query;

        // Implement circular buffer: remove oldest queries when limit exceeded
        if (count($this->queries) > $this->maxQueries) {
            $this->queries = array_slice($this->queries, -$this->maxQueries);
        }
    }

    /**
     * @return Query[]
     */
    public function getQueries(): array
    {
        return $this->queries;
    }

    public function resetQueries(): void
    {
        $this->queries = [];
    }

    /**
     * Set the maximum number of queries to keep in memory.
     *
     * @param int $max Maximum query count (minimum 10)
     */
    public function setMaxQueries(int $max): void
    {
        $this->maxQueries = max(10, $max);
    }

    /**
     * Get the current query limit.
     */
    public function getMaxQueries(): int
    {
        return $this->maxQueries;
    }
}