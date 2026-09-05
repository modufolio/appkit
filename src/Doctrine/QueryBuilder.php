<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder as DBALQueryBuilder;

/**
 * Fluent wrapper around DBAL's query builder.
 *
 * Values are always bound as parameters. Identifiers — table, column and
 * alias names handed to from(), select(), where*(), join*(), orderBy(),
 * groupBy(), insert() and update() — are validated against a strict
 * identifier grammar (`name`, `alias.name`, `schema.table.name`, `*`,
 * `alias.*`) and rejected with an InvalidArgumentException otherwise. This
 * closes the classic injection through `orderBy($_GET['sort'])`: a value that
 * is not a plain identifier never reaches the SQL string. Comparison
 * operators are allowlisted the same way.
 *
 * Validation does not make a request-controlled identifier *safe*, only
 * non-injectable — it can still name any column. For user-selectable sorting
 * use orderByAllowed(), which additionally restricts the column to a list you
 * define. Anything that needs an expression (functions, CASE, arithmetic) goes
 * through selectRaw(), whereRaw() or whereExpression(), which are raw by
 * design and must never be fed request input.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class QueryBuilder
{
    /**
     * A bare identifier: letters, digits and underscores, not starting with a digit.
     */
    private const IDENTIFIER = '[A-Za-z_][A-Za-z0-9_]*';

    /**
     * A column reference: `name`, `alias.name`, `schema.table.name`, plus the
     * `*` / `alias.*` forms select() accepts.
     */
    private const COLUMN_PATTERN = '/^(?:\*|'.self::IDENTIFIER.'(?:\.'.self::IDENTIFIER.'){0,2}(?:\.\*)?)$/';

    /**
     * A table reference: `table` or `schema.table`.
     */
    private const TABLE_PATTERN = '/^'.self::IDENTIFIER.'(?:\.'.self::IDENTIFIER.')?$/';

    private const NAME_PATTERN = '/^'.self::IDENTIFIER.'$/';

    private const OPERATORS = ['=', '<>', '!=', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'ILIKE', 'NOT ILIKE'];

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
        $this->assertTable($table);
        if (null !== $alias) {
            $this->assertName($alias, 'alias');
        }

        $this->table = $table;
        $this->alias = $alias ?? $table;
        $this->queryBuilder->from($table, $this->alias);

        return $this;
    }

    /**
     * Select columns by name (`id`, `u.name`, `u.*`), or `['column' => 'alias']`
     * to alias them. Expressions such as `COUNT(*)` are rejected — use
     * selectRaw() for those.
     *
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
                    $this->assertColumn((string) $col);
                    $this->assertName($alias, 'alias');
                    $this->queryBuilder->addSelect(sprintf('%s AS %s', $col, $alias));
                }
            } else {
                $this->assertColumn($column);
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
        $this->assertColumn($column);
        $operator = $this->normalizeOperator($operator);

        $param = $this->newParamName();
        $this->queryBuilder->andWhere($this->expr->comparison($column, $operator, ':'.$param));
        $this->queryBuilder->setParameter($param, $value);

        return $this;
    }

    public function orWhere(string $column, string $operator, mixed $value): self
    {
        $this->assertColumn($column);
        $operator = $this->normalizeOperator($operator);

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
        $this->assertColumn($column);

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
        $this->assertColumn($column);

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
        $this->assertColumn($column);
        $this->queryBuilder->andWhere($this->expr->isNull($column));

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->assertColumn($column);
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
        $this->queryBuilder->innerJoin($this->requireAlias(), $table, $alias, $this->joinCondition($table, $alias, $first, $operator, $second));

        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second, ?string $alias = null): self
    {
        $alias ??= $table;
        $this->queryBuilder->leftJoin($this->requireAlias(), $table, $alias, $this->joinCondition($table, $alias, $first, $operator, $second));

        return $this;
    }

    public function rightJoin(string $table, string $first, string $operator, string $second, ?string $alias = null): self
    {
        $alias ??= $table;
        $this->queryBuilder->rightJoin($this->requireAlias(), $table, $alias, $this->joinCondition($table, $alias, $first, $operator, $second));

        return $this;
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Sorting, grouping, limits
    // ────────────────────────────────────────────────────────────────────────────────

    /**
     * `$column` must be a plain column reference; an expression or anything
     * else that is not an identifier throws. Prefer orderByAllowed() when the
     * column comes from the request.
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->assertColumn($column, allowWildcard: false);
        $this->queryBuilder->addOrderBy($column, $this->normalizeDirection($direction));

        return $this;
    }

    /**
     * Sort by a request-controlled column safely.
     *
     * `$allowed` is the set of columns the caller permits: either a list of
     * column references (`['name', 'created_at']`) or a map from the public
     * sort key to the real column (`['name' => 'u.name', 'date' => 'u.created_at']`).
     * A `$column` outside that set, or a `$direction` other than asc/desc,
     * throws an InvalidArgumentException — which the exception handler turns
     * into a 400, the right answer to a tampered `?sort=` parameter.
     *
     *     $qb->orderByAllowed($query['sort'] ?? 'name', $query['dir'] ?? 'asc', [
     *         'name' => 'u.name',
     *         'date' => 'u.created_at',
     *     ]);
     *
     * @param array<int|string, string> $allowed
     */
    public function orderByAllowed(string $column, string $direction, array $allowed): self
    {
        $map = [];
        foreach ($allowed as $key => $target) {
            $map[is_int($key) ? $target : (string) $key] = $target;
        }

        if (!array_key_exists($column, $map)) {
            throw new \InvalidArgumentException(sprintf('Cannot sort by "%s"; allowed: %s.', $column, implode(', ', array_map(static fn (string $k): string => '"'.$k.'"', array_keys($map)))));
        }

        return $this->orderBy($map[$column], $direction);
    }

    public function groupBy(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->assertColumn($column, allowWildcard: false);
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
     *
     * @throws DbalException
     */
    public function insert(array $values): int
    {
        $this->queryBuilder->insert($this->table);

        foreach ($values as $column => $value) {
            $this->assertName((string) $column, 'column');
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
            $this->assertName((string) $column, 'column');
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
     *
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
                throw new \InvalidArgumentException(sprintf('Raw expression "%s" has fewer ? placeholders than bindings (%d given).', $expression, count($bindings)));
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

    // ────────────────────────────────────────────────────────────────────────────────
    // Identifier validation
    // ────────────────────────────────────────────────────────────────────────────────

    /**
     * @throws \InvalidArgumentException when $column is not a plain column reference
     */
    private function assertColumn(string $column, bool $allowWildcard = true): void
    {
        if (1 !== preg_match(self::COLUMN_PATTERN, $column) || (!$allowWildcard && str_contains($column, '*'))) {
            throw new \InvalidArgumentException(sprintf('Invalid column identifier "%s": expected a plain name such as "id" or "u.name". Use selectRaw()/whereRaw() for expressions.', $column));
        }
    }

    /**
     * @throws \InvalidArgumentException when $table is not `table` or `schema.table`
     */
    private function assertTable(string $table): void
    {
        if (1 !== preg_match(self::TABLE_PATTERN, $table)) {
            throw new \InvalidArgumentException(sprintf('Invalid table identifier "%s".', $table));
        }
    }

    /**
     * @throws \InvalidArgumentException when $name is not a bare identifier
     */
    private function assertName(string $name, string $what): void
    {
        if (1 !== preg_match(self::NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException(sprintf('Invalid %s identifier "%s".', $what, $name));
        }
    }

    /**
     * @throws \InvalidArgumentException when $operator is not an allowlisted comparison
     */
    private function normalizeOperator(string $operator): string
    {
        $normalized = strtoupper(trim(preg_replace('/\s+/', ' ', $operator) ?? $operator));

        if (!in_array($normalized, self::OPERATORS, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid comparison operator "%s"; allowed: %s.', $operator, implode(', ', self::OPERATORS)));
        }

        return $normalized;
    }

    /**
     * @throws \InvalidArgumentException when $direction is not asc/desc
     */
    private function normalizeDirection(string $direction): string
    {
        $direction = strtoupper(trim($direction));
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException("Invalid order direction: {$direction}");
        }

        return $direction;
    }

    /**
     * Validated `first <op> second` join condition.
     */
    private function joinCondition(string $table, string $alias, string $first, string $operator, string $second): string
    {
        $this->assertTable($table);
        $this->assertName($alias, 'alias');
        $this->assertColumn($first, allowWildcard: false);
        $this->assertColumn($second, allowWildcard: false);

        return $first.' '.$this->normalizeOperator($operator).' '.$second;
    }
}
