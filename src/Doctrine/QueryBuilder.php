<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder as DBALQueryBuilder;

/**
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class QueryBuilder
{
    private Connection $connection;
    private DBALQueryBuilder $queryBuilder;
    private ExpressionBuilder $expr;
    private string $table;
    private ?string $alias = null;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->queryBuilder = $connection->createQueryBuilder();
        $this->expr = $this->queryBuilder->expr();
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Core builder
    // ────────────────────────────────────────────────────────────────────────────────

    public function from(string $table, ?string $alias = null): self
    {
        $this->table = $table;
        $this->alias = $alias ?? $table;
        $this->queryBuilder->from($table, $this->alias);

        return $this;
    }

    /**
     * @param string|array<string, string> ...$columns
     */
    public function select(string|array ...$columns): self
    {
        if (empty($columns)) {
            $this->queryBuilder->select('*');

            return $this;
        }

        foreach ($columns as $column) {
            if (is_array($column)) {
                foreach ($column as $col => $alias) {
                    $this->queryBuilder->addSelect(sprintf('%s AS %s', $col, $alias));
                }
            } else {
                $this->queryBuilder->addSelect($column);
            }
        }

        return $this;
    }

    /**
     * Select a raw expression. Each `?` placeholder is bound to the matching
     * value, in order: `selectRaw('age + ? AS age_plus', [1])`.
     *
     * @param list<mixed> $bindings
     */
    public function selectRaw(string $expression, array $bindings = []): self
    {
        $this->queryBuilder->addSelect($this->bindPositional($expression, $bindings));

        return $this;
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // WHERE conditions
    // ────────────────────────────────────────────────────────────────────────────────

    public function where(string $column, string $operator, mixed $value): self
    {
        $param = $this->newParamName();
        $this->queryBuilder->andWhere($this->expr->comparison($column, $operator, ':'.$param));
        $this->queryBuilder->setParameter($param, $value);

        return $this;
    }

    public function orWhere(string $column, string $operator, mixed $value): self
    {
        $param = $this->newParamName();
        $this->queryBuilder->orWhere($this->expr->comparison($column, $operator, ':'.$param));
        $this->queryBuilder->setParameter($param, $value);

        return $this;
    }

    /**
     * An empty list matches no rows. That is rendered as `1 = 0` rather than
     * `IN ()`, which SQLite happens to parse but MySQL rejects outright.
     *
     * @param list<mixed> $values
     */
    public function whereIn(string $column, array $values): self
    {
        if ([] === $values) {
            $this->queryBuilder->andWhere('1 = 0');

            return $this;
        }

        $this->queryBuilder->andWhere($this->expr->in($column, $this->bindList($values)));

        return $this;
    }

    /**
     * An empty list excludes no rows, so the clause is simply omitted.
     *
     * @param list<mixed> $values
     */
    public function whereNotIn(string $column, array $values): self
    {
        if ([] === $values) {
            return $this;
        }

        $this->queryBuilder->andWhere($this->expr->notIn($column, $this->bindList($values)));

        return $this;
    }

    /**
     * Bind each value to a generated named parameter, returning placeholders.
     *
     * @param non-empty-list<mixed> $values
     *
     * @return non-empty-list<string>
     */
    private function bindList(array $values): array
    {
        $params = [];
        foreach ($values as $value) {
            $param = $this->newParamName();
            $params[] = ':'.$param;
            $this->queryBuilder->setParameter($param, $value);
        }

        return $params;
    }

    public function whereNull(string $column): self
    {
        $this->queryBuilder->andWhere($this->expr->isNull($column));

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->queryBuilder->andWhere($this->expr->isNotNull($column));

        return $this;
    }

    public function whereExpression(callable $callback): self
    {
        $expression = $callback($this->expr);
        $this->queryBuilder->andWhere('('.$expression.')');

        return $this;
    }

    public function orWhereExpression(callable $callback): self
    {
        $expression = $callback($this->expr);
        $this->queryBuilder->orWhere('('.$expression.')');

        return $this;
    }

    /**
     * Raw WHERE expression. Each `?` placeholder is bound to the matching
     * value, in order: `whereRaw('age > ?', [25])`. The caller never sees or
     * names the underlying parameters, so raw clauses compose safely with
     * where()/whereIn() no matter how many parameters are already bound.
     *
     * @param list<mixed> $bindings
     */
    public function whereRaw(string $expression, array $bindings = []): self
    {
        $this->queryBuilder->andWhere($this->bindPositional($expression, $bindings));

        return $this;
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Joins
    // ────────────────────────────────────────────────────────────────────────────────

    public function join(string $table, string $first, string $operator, string $second, ?string $alias = null): self
    {
        $alias ??= $table;
        $this->queryBuilder->innerJoin($this->requireAlias(), $table, $alias, "{$first} {$operator} {$second}");

        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second, ?string $alias = null): self
    {
        $alias ??= $table;
        $this->queryBuilder->leftJoin($this->requireAlias(), $table, $alias, "{$first} {$operator} {$second}");

        return $this;
    }

    public function rightJoin(string $table, string $first, string $operator, string $second, ?string $alias = null): self
    {
        $alias ??= $table;
        $this->queryBuilder->rightJoin($this->requireAlias(), $table, $alias, "{$first} {$operator} {$second}");

        return $this;
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Sorting, grouping, limits
    // ────────────────────────────────────────────────────────────────────────────────

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException("Invalid order direction: {$direction}");
        }
        $this->queryBuilder->addOrderBy($column, $direction);

        return $this;
    }

    public function groupBy(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->queryBuilder->addGroupBy($column);
        }

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->queryBuilder->setMaxResults($limit);

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->queryBuilder->setFirstResult($offset);

        return $this;
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // CRUD Operations
    // ────────────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $values
     * @throws DbalException
     */
    public function insert(array $values): int
    {
        $this->queryBuilder->insert($this->table);

        foreach ($values as $column => $value) {
            $param = $column;
            $this->queryBuilder->setValue($column, ':'.$param);
            $this->queryBuilder->setParameter($param, $value);
        }

        return (int) $this->queryBuilder->executeStatement();
    }

    /**
     * @param array<string, mixed> $values
     *
     * @throws DbalException
     */
    public function update(array $values): int
    {
        $this->queryBuilder->update($this->table);

        foreach ($values as $column => $value) {
            $param = $column;
            $this->queryBuilder->set($column, ':'.$param);
            $this->queryBuilder->setParameter($param, $value);
        }

        return (int) $this->queryBuilder->executeStatement();
    }

    /**
     * @throws DbalException
     */
    public function delete(): int
    {
        $this->queryBuilder->delete($this->table);

        return (int) $this->queryBuilder->executeStatement();
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Fetching
    // ────────────────────────────────────────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     *
     * @throws DbalException
     */
    public function get(): array
    {
        return $this->queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function first(): ?array
    {
        $result = $this->limit(1)->get();

        return $result[0] ?? null;
    }

    /**
     * @throws DbalException
     */
    public function count(): int
    {
        // Ensure there's a SELECT clause - check if query type is set
        try {
            $sql = $this->queryBuilder->getSQL();
        } catch (DbalException $e) {
            // No SELECT set yet, add default
            $this->queryBuilder->select('*');
            $sql = $this->queryBuilder->getSQL();
        }

        $params = $this->queryBuilder->getParameters();

        // Execute as subquery wrapped in COUNT
        $countSql = 'SELECT COUNT(*) AS cnt FROM ('.$sql.') AS count_wrapper';

        return (int) $this->connection->executeQuery($countSql, $params)->fetchOne();
    }

    /**
     * @return list<mixed>
     *
     * @throws DbalException
     */
    public function fetchColumn(string $column): array
    {
        return array_column($this->get(), $column);
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Utility
    // ────────────────────────────────────────────────────────────────────────────────

    /**
     * @throws DbalException
     */
    public function toSql(): string
    {
        return $this->queryBuilder->getSQL();
    }

    public function getQueryBuilder(): DBALQueryBuilder
    {
        return $this->queryBuilder;
    }

    public function expr(): ExpressionBuilder
    {
        return $this->expr;
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ────────────────────────────────────────────────────────────────────────────────

    /**
     * Rewrite `?` placeholders in a raw expression to freshly generated named
     * parameters and bind the values. DBAL forbids mixing positional and
     * named parameters in one query, and where()/whereIn() already bind named
     * ones — rewriting keeps raw clauses composable with them.
     *
     * Too many bindings for the placeholders is always a bug and throws; a
     * leftover `?` is left alone, since it may be a literal inside the
     * expression (the database reports a genuinely missing parameter).
     *
     * @param list<mixed> $bindings
     */
    private function bindPositional(string $expression, array $bindings): string
    {
        foreach ($bindings as $value) {
            $position = strpos($expression, '?');
            if (false === $position) {
                throw new \InvalidArgumentException(sprintf(
                    'Raw expression "%s" has fewer ? placeholders than bindings (%d given).',
                    $expression,
                    count($bindings),
                ));
            }

            $param = $this->newParamName();
            $this->queryBuilder->setParameter($param, $value);
            $expression = substr_replace($expression, ':'.$param, $position, 1);
        }

        return $expression;
    }

    private function newParamName(): string
    {
        return 'p'.count($this->queryBuilder->getParameters());
    }

    /**
     * The alias set by from(), which every join needs as its left-hand side.
     */
    private function requireAlias(): string
    {
        if (null === $this->alias) {
            throw new \LogicException('Call from() before adding a join.');
        }

        return $this->alias;
    }
}
