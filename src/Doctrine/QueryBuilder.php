<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder as DBALQueryBuilder;
use InvalidArgumentException;

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

    public function select(...$columns): self
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

    public function selectRaw(string $expression, array $bindings = []): self
    {
        $this->queryBuilder->addSelect($expression);
        foreach ($bindings as $value) {
            $this->addBinding($value);
        }
        return $this;
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // WHERE conditions
    // ────────────────────────────────────────────────────────────────────────────────

    public function where(string $column, string $operator, mixed $value): self
    {
        $param = $this->newParamName();
        $this->queryBuilder->andWhere($this->expr->comparison($column, $operator, ':' . $param));
        $this->queryBuilder->setParameter($param, $value);
        return $this;
    }

    public function orWhere(string $column, string $operator, mixed $value): self
    {
        $param = $this->newParamName();
        $this->queryBuilder->orWhere($this->expr->comparison($column, $operator, ':' . $param));
        $this->queryBuilder->setParameter($param, $value);
        return $this;
    }

    public function whereIn(string $column, array $values): self
    {
        $params = [];
        foreach ($values as $value) {
            $param = $this->newParamName();
            $params[] = ':' . $param;
            $this->queryBuilder->setParameter($param, $value);
        }
        $this->queryBuilder->andWhere($this->expr->in($column, $params));
        return $this;
    }

    public function whereNotIn(string $column, array $values): self
    {
        $params = [];
        foreach ($values as $value) {
            $param = $this->newParamName();
            $params[] = ':' . $param;
            $this->queryBuilder->setParameter($param, $value);
        }
        $this->queryBuilder->andWhere($this->expr->notIn($column, $params));
        return $this;
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
        $this->queryBuilder->andWhere('(' . $expression . ')');
        return $this;
    }

    public function orWhereExpression(callable $callback): self
    {
        $expression = $callback($this->expr);
        $this->queryBuilder->orWhere('(' . $expression . ')');
        return $this;
    }

    public function whereRaw(string $expression, array $bindings = []): self
    {
        $this->queryBuilder->andWhere($expression);
        foreach ($bindings as $value) {
            $this->addBinding($value);
        }
        return $this;
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Joins
    // ────────────────────────────────────────────────────────────────────────────────

    public function join(string $table, string $first, string $operator, string $second, ?string $alias = null): self
    {
        $alias ??= $table;
        $this->queryBuilder->innerJoin($this->alias, $table, $alias, "$first $operator $second");
        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second, ?string $alias = null): self
    {
        $alias ??= $table;
        $this->queryBuilder->leftJoin($this->alias, $table, $alias, "$first $operator $second");
        return $this;
    }

    public function rightJoin(string $table, string $first, string $operator, string $second, ?string $alias = null): self
    {
        $alias ??= $table;
        $this->queryBuilder->rightJoin($this->alias, $table, $alias, "$first $operator $second");
        return $this;
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Sorting, grouping, limits
    // ────────────────────────────────────────────────────────────────────────────────

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException("Invalid order direction: {$direction}");
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

    public function insert(array $values): int
    {
        $this->queryBuilder->insert($this->table);

        foreach ($values as $column => $value) {
            $param = $column;
            $this->queryBuilder->setValue($column, ':' . $param);
            $this->queryBuilder->setParameter($param, $value);
        }

        return $this->queryBuilder->executeStatement();
    }

    /**
     * @throws Exception
     */
    public function update(array $values): int
    {
        $this->queryBuilder->update($this->table);

        foreach ($values as $column => $value) {
            $param = $column;
            $this->queryBuilder->set($column, ':' . $param);
            $this->queryBuilder->setParameter($param, $value);
        }

        return $this->queryBuilder->executeStatement();
    }

    /**
     * @throws Exception
     */
    public function delete(): int
    {
        $this->queryBuilder->delete($this->table);
        return $this->queryBuilder->executeStatement();
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Fetching
    // ────────────────────────────────────────────────────────────────────────────────

    /**
     * @throws Exception
     */
    public function get(): array
    {
        return $this->queryBuilder->executeQuery()->fetchAllAssociative();
    }

    public function first(): ?array
    {
        $result = $this->limit(1)->get();
        return $result[0] ?? null;
    }

    public function count(): int
    {
        // Ensure there's a SELECT clause - check if query type is set
        try {
            $sql = $this->queryBuilder->getSQL();
        } catch (\Exception $e) {
            // No SELECT set yet, add default
            $this->queryBuilder->select('*');
            $sql = $this->queryBuilder->getSQL();
        }

        $params = $this->queryBuilder->getParameters();

        // Execute as subquery wrapped in COUNT
        $countSql = 'SELECT COUNT(*) AS cnt FROM (' . $sql . ') AS count_wrapper';

        return (int) $this->connection->executeQuery($countSql, $params)->fetchOne();
    }

    /**
     * @throws Exception
     */
    public function fetchColumn(string $column): array
    {
        return array_column($this->get(), $column);
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Utility
    // ────────────────────────────────────────────────────────────────────────────────

    /**
     * @throws Exception
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

    private function addBinding(mixed $value): void
    {
        $param = $this->newParamName();
        $this->queryBuilder->setParameter($param, $value);
    }

    private function newParamName(): string
    {
        return 'p' . count($this->queryBuilder->getParameters());
    }
}