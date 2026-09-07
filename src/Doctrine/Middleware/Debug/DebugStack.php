<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Doctrine\Middleware\Debug;

/**
 * Debug stack for tracking SQL queries with bounded memory usage.
 *
 * Memory Leak Prevention:
 * - Implements a circular buffer to limit query history
 * - Prevents unbounded memory growth in long-running RoadRunner workers
 * - Default limit of 100 queries provides sufficient debugging context
 *
 * @author    Filippo Tessarotto <zoeslam@gmail.com>
 * @author    Maarten Thiebou
 *
 * @see       https://github.com/Slamdunk/dbal-debugstack-middleware
 *
 * @copyright Filippo Tessarotto
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
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

    /**
     * @param bool $collectOrigin record the application file and line that issued each
     *                            query (a backtrace per query — dev only; the kernel
     *                            turns it on there). What makes an N+1 report say
     *                            which loop, not just which SQL.
     */
    public function __construct(private bool $collectOrigin = false)
    {
    }

    public function collectOrigin(bool $collect = true): void
    {
        $this->collectOrigin = $collect;
    }

    public function isCollectingOrigin(): bool
    {
        return $this->collectOrigin;
    }

    public function append(Query $query): void
    {
        if ($this->collectOrigin && null === $query->file && null !== ($origin = $this->origin())) {
            $query = $query->withOrigin(...$origin);
        }

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
     * The first frame that belongs to the application: not Doctrine, not this
     * middleware, not anything installed under vendor/ — for a consumer that
     * includes the framework itself, so a repository base class or the query
     * builder never counts as the origin.
     *
     * @return array{string, int}|null
     */
    private function origin(): ?array
    {
        foreach (debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 60) as $frame) {
            $file = $frame['file'] ?? null;

            if (null === $file
                || str_contains($file, \DIRECTORY_SEPARATOR.'vendor'.\DIRECTORY_SEPARATOR)
                || str_contains($file, \DIRECTORY_SEPARATOR.'src'.\DIRECTORY_SEPARATOR.'Doctrine'.\DIRECTORY_SEPARATOR)) {
                continue;
            }

            return [$file, (int) ($frame['line'] ?? 0)];
        }

        return null;
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
