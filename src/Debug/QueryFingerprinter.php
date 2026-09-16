<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Debug;

use Modufolio\Appkit\Doctrine\Middleware\Debug\DebugStack;
use Modufolio\Appkit\Doctrine\Middleware\Debug\Query;

/**
 * Finds the N+1 in a request's queries by shape, not by text.
 *
 * `SELECT … WHERE project_id = 8` and `… = 9` are one shape; so are two
 * `IN (…)` lists of different length. Reduce every statement of a request
 * to its shape, count, and the loop that fetched one row per parent shows
 * up as a shape repeated as many times as there were parents. Below the
 * threshold a repeat is normal — a lookup and its count, an entity fetched
 * twice on two paths — and is not reported.
 *
 * Pure: it sees strings and numbers, so it runs anywhere the DebugStack's
 * queries are available — a profiler's `collect()`, a test assertion, a
 * console report.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class QueryFingerprinter
{
    /**
     * Below this many repeats a shape is normal; an N+1 overshoots it clearly.
     */
    public const DEFAULT_THRESHOLD = 3;

    public function __construct(
        private readonly int $threshold = self::DEFAULT_THRESHOLD,
    ) {
        if ($threshold < 2) {
            throw new \InvalidArgumentException('The threshold must be at least 2; a shape seen once is not a repeat.');
        }
    }

    /**
     * Reduce a statement to its shape: whitespace collapsed, literals and
     * numbers masked, IN lists of any arity made one.
     */
    public function fingerprint(string $sql): string
    {
        $sql = (string) preg_replace('/\s+/', ' ', trim($sql));
        // Literals before numbers, so digits inside strings are not touched
        // twice; an escaped quote ('') is consumed by the repetition.
        $sql = (string) preg_replace("/'(?:[^']|'')*'/", '?', $sql);
        $sql = (string) preg_replace('/\b-?\d+(?:\.\d+)?\b/', '?', $sql);

        // Varying arity is a symptom of batching, not a different query.
        return (string) preg_replace('/\bIN\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'IN (?)', $sql);
    }

    /**
     * Group a request's queries by shape and report the repeated ones,
     * most repeated first.
     *
     * @param iterable<Query|array{sql: string, ms: float|int}> $queries the DebugStack's records, or plain sql/ms pairs
     *
     * @return array{suspects: list<array{sql: string, count: int, totalMs: float}>, distinctShapes: int}
     */
    public function analyze(iterable $queries): array
    {
        /** @var array<string, array{sql: string, count: int, totalMs: float}> $shapes */
        $shapes = [];

        foreach ($queries as $query) {
            [$sql, $ms] = $query instanceof Query
                ? [$query->sql, $query->executionMs]
                : [$query['sql'], (float) $query['ms']];

            // The debug middleware records connection and transaction
            // boundaries as pseudo-statements; they repeat by nature.
            if ('CONNECT' === $sql || str_ends_with($sql, 'TRANSACTION')) {
                continue;
            }

            $shape = $this->fingerprint($sql);
            $shapes[$shape] ??= ['sql' => $shape, 'count' => 0, 'totalMs' => 0.0];
            ++$shapes[$shape]['count'];
            $shapes[$shape]['totalMs'] += $ms;
        }

        $suspects = array_values(array_filter($shapes, fn (array $shape): bool => $shape['count'] >= $this->threshold));
        usort($suspects, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'suspects' => array_map(static fn (array $shape): array => [
                'sql' => $shape['sql'],
                'count' => $shape['count'],
                'totalMs' => round($shape['totalMs'], 3),
            ], $suspects),
            'distinctShapes' => \count($shapes),
        ];
    }

    /**
     * {@see analyze()} over everything a DebugStack recorded this request.
     *
     * @return array{suspects: list<array{sql: string, count: int, totalMs: float}>, distinctShapes: int}
     */
    public function analyzeStack(DebugStack $stack): array
    {
        return $this->analyze($stack->getQueries());
    }
}
