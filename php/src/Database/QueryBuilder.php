<?php

declare(strict_types=1);

namespace Fium\Database;

/**
 * A small, safe, dependency-free fluent query builder over PDO.
 *
 * Design notes:
 *  - Values are ALWAYS bound as parameters (never interpolated), preventing SQL
 *    injection from data.
 *  - Identifiers (table and column names) are validated against a strict
 *    charset and used verbatim, since SQL has no parameterization for them.
 *  - Clauses are immutable-style: each builder method returns $this for
 *    chaining, building up SQL fragments + bindings that are compiled on demand.
 */
final class QueryBuilder
{
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'like', 'not like'];

    /** @var list<string> */
    private array $columns = ['*'];
    /** @var list<string> */
    private array $wheres = [];
    /** @var array<string, mixed> */
    private array $bindings = [];
    /** @var list<string> */
    private array $joins = [];
    /** @var list<string> */
    private array $orders = [];
    /** @var list<string> */
    private array $groups = [];
    private ?int $limitValue = null;
    private ?int $offsetValue = null;
    private int $paramCounter = 0;

    public function __construct(private \PDO $pdo, private string $table)
    {
        $this->table = $this->validateIdentifier($this->table);
    }

    public function select(string ...$columns): self
    {
        $this->columns = $columns === []
            ? ['*']
            : array_map([$this, 'validateSelectColumn'], array_values($columns));

        return $this;
    }

    /**
     * Set a raw SELECT expression (e.g. `count(*) AS total`).
     * The caller is responsible for ensuring it is injection-safe.
     */
    public function selectRaw(string $expression): self
    {
        $this->columns = [$expression];

        return $this;
    }

    /** @return array{0: string, 1: mixed} */
    private function normalizeCondition(int $argc, mixed $operator, mixed $value): array
    {
        if ($argc === 2) {
            // Two-arg form: where($column, $value) implies '='.
            return ['=', $operator];
        }

        return [strtolower((string) $operator), $value];
    }

    public function where(string $column, mixed $operator, mixed $value = null): self
    {
        [$op, $val] = $this->normalizeCondition(func_num_args(), $operator, $value);

        return $this->pushWhere('AND', $column, $op, $val);
    }

    public function orWhere(string $column, mixed $operator, mixed $value = null): self
    {
        [$op, $val] = $this->normalizeCondition(func_num_args(), $operator, $value);

        return $this->pushWhere('OR', $column, $op, $val);
    }

    /**
     * @param list<mixed> $values
     */
    public function whereIn(string $column, array $values): self
    {
        if ($values === []) {
            // `IN ()` is invalid SQL — render a guaranteed-false condition.
            $this->wheres[] = $this->connector('AND') . '0 = 1';

            return $this;
        }

        $placeholders = [];
        foreach ($values as $value) {
            $param = $this->nextParam();
            $this->bindings[$param] = $value;
            $placeholders[] = $param;
        }

        $this->wheres[] = $this->connector('AND') . $this->validateIdentifier($column)
            . ' IN (' . implode(', ', $placeholders) . ')';

        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->wheres[] = $this->connector('AND') . $this->validateIdentifier($column) . ' IS NULL';

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->wheres[] = $this->connector('AND') . $this->validateIdentifier($column) . ' IS NOT NULL';

        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second): self
    {
        return $this->pushJoin('INNER', $table, $first, $operator, $second);
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->pushJoin('LEFT', $table, $first, $operator, $second);
    }

    private function pushJoin(string $type, string $table, string $first, string $operator, string $second): self
    {
        $operator = strtolower($operator);
        if (!in_array($operator, ['=', '!=', '<', '<=', '>', '>='], true)) {
            throw new \InvalidArgumentException("Unsupported join operator '{$operator}'.");
        }

        $this->joins[] = "{$type} JOIN " . $this->validateIdentifier($table)
            . ' ON ' . $this->validateIdentifier($first)
            . ' ' . $operator . ' '
            . $this->validateIdentifier($second);

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $direction = strtoupper($direction);
        if ($direction !== 'ASC' && $direction !== 'DESC') {
            throw new \InvalidArgumentException("Order direction must be 'asc' or 'desc'.");
        }

        $this->orders[] = $this->validateIdentifier($column) . ' ' . $direction;

        return $this;
    }

    public function groupBy(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->groups[] = $this->validateIdentifier($column);
        }

        return $this;
    }

    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new \InvalidArgumentException('Limit must be non-negative.');
        }
        $this->limitValue = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException('Offset must be non-negative.');
        }
        $this->offsetValue = $offset;

        return $this;
    }

    /**
     * Execute and return all matching rows.
     *
     * @return list<array<string, mixed>>
     */
    public function get(): array
    {
        $statement = $this->pdo->prepare($this->toSql());
        $statement->execute($this->bindings);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * Execute and return the first matching row, or null.
     *
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        $previousLimit = $this->limitValue;
        $this->limitValue = 1;

        try {
            $rows = $this->get();
        } finally {
            $this->limitValue = $previousLimit;
        }

        return $rows === [] ? null : $rows[0];
    }

    public function count(): int
    {
        $this->columns = ['count(*) AS aggregate'];
        $rows = $this->get();

        return (int) ($rows[0]['aggregate'] ?? 0);
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Insert a row and return the last inserted id (as a string).
     *
     * @param array<string, mixed> $values
     */
    public function insert(array $values): string
    {
        if ($values === []) {
            throw new \InvalidArgumentException('Cannot insert an empty row.');
        }

        $columns = array_map([$this, 'validateIdentifier'], array_keys($values));
        $placeholders = [];
        $bindings = [];

        foreach ($values as $column => $value) {
            $param = $this->nextParam();
            $bindings[$param] = $value;
            $placeholders[] = $param;
        }

        $sql = 'INSERT INTO ' . $this->table
            . ' (' . implode(', ', $columns) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')';

        $this->pdo->prepare($sql)->execute($bindings);

        return (string) $this->pdo->lastInsertId();
    }

    /**
     * Update matching rows. Returns the number of affected rows.
     *
     * @param array<string, mixed> $values
     */
    public function update(array $values): int
    {
        if ($values === []) {
            throw new \InvalidArgumentException('Cannot update with an empty set.');
        }

        $assignments = [];
        $bindings = [];

        foreach ($values as $column => $value) {
            $param = $this->nextParam();
            $bindings[$param] = $value;
            $assignments[] = $this->validateIdentifier($column) . ' = ' . $param;
        }

        $sql = 'UPDATE ' . $this->table . ' SET ' . implode(', ', $assignments) . $this->compileWheres();

        // WHERE bindings must be appended after SET bindings.
        $statement = $this->pdo->prepare($sql);
        $statement->execute(array_merge($bindings, $this->bindings));

        return $statement->rowCount();
    }

    /**
     * Delete matching rows. Returns the number of affected rows.
     */
    public function delete(): int
    {
        $sql = 'DELETE FROM ' . $this->table . $this->compileWheres();
        $statement = $this->pdo->prepare($sql);
        $statement->execute($this->bindings);

        return $statement->rowCount();
    }

    /**
     * Compile the SELECT SQL without executing.
     */
    public function toSql(): string
    {
        $columns = implode(', ', $this->columns);
        $sql = 'SELECT ' . $columns . ' FROM ' . $this->table;

        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        $sql .= $this->compileWheres();

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }

        if ($this->limitValue !== null) {
            $sql .= ' LIMIT ' . $this->limitValue;
        }

        if ($this->offsetValue !== null) {
            $sql .= ' OFFSET ' . $this->offsetValue;
        }

        return $sql;
    }

    /** @return array<string, mixed> */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    private function compileWheres(): string
    {
        if ($this->wheres === []) {
            return '';
        }

        return ' WHERE ' . implode('', $this->wheres);
    }

    private function pushWhere(string $boolean, string $column, string $operator, mixed $value): self
    {
        $prefix = $this->connector($boolean);
        $column = $this->validateIdentifier($column);

        if ($value === null) {
            $this->wheres[] = $prefix . $column . ' IS NULL';

            return $this;
        }

        if (!in_array($operator, self::OPERATORS, true)) {
            throw new \InvalidArgumentException("Unsupported operator '{$operator}'.");
        }

        $param = $this->nextParam();
        $this->bindings[$param] = $value;
        $this->wheres[] = $prefix . $column . ' ' . $operator . ' ' . $param;

        return $this;
    }

    /**
     * Return the boolean connector (empty for the first clause, else " AND "/" OR ").
     */
    private function connector(string $boolean): string
    {
        return $this->wheres === [] ? '' : ' ' . $boolean . ' ';
    }

    private function nextParam(): string
    {
        return ':fium_p' . ($this->paramCounter++);
    }

    private function validateSelectColumn(string $column): string
    {
        if ($column === '*') {
            return '*';
        }

        return $this->validateIdentifier($column);
    }

    private function validateIdentifier(string $identifier): string
    {
        foreach (explode('.', $identifier) as $part) {
            if ($part === '*') {
                continue;
            }

            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $part)) {
                throw new \InvalidArgumentException("Invalid SQL identifier '{$identifier}'.");
            }
        }

        return $identifier;
    }
}
