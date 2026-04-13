<?php

declare(strict_types=1);

namespace Wayfinder\Database;

final class QueryBuilder
{
    private string $operation = 'select';

    /**
     * @var list<string>
     */
    private array $joins = [];

    /**
     * @var list<array{boolean: 'and'|'or', clause: string}>
     */
    private array $whereClauses = [];

    /**
     * @var list<string>
     */
    private array $orderings = [];

    /**
     * @var list<mixed>
     */
    private array $bindings = [];

    /**
     * @var array<string, mixed>
     */
    private array $updateData = [];

    private string $columns = '*';

    private ?int $limitValue = null;

    private ?int $offsetValue = null;

    public function __construct(
        private readonly Database $database,
        private readonly string $table,
    ) {
    }

    public function select(string|array $columns = '*'): self
    {
        $this->operation = 'select';
        $this->columns = $this->database->normalizeColumnList($columns);

        return $this;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        if ($data === []) {
            throw new \InvalidArgumentException('Insert data cannot be empty.');
        }

        $columns = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        return $this->database->statement(
            sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $this->database->qualifyIdentifier($this->table),
                implode(', ', array_map($this->database->qualifyIdentifier(...), $columns)),
                $placeholders,
            ),
            array_values($data),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(array $data): int
    {
        return $this->prepareUpdate($data)->execute();
    }

    public function delete(): int
    {
        return $this->prepareDelete()->execute();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function prepareUpdate(array $data): self
    {
        if ($data === []) {
            throw new \InvalidArgumentException('Update data cannot be empty.');
        }

        $this->operation = 'update';
        $this->updateData = $data;

        return $this;
    }

    public function prepareDelete(): self
    {
        $this->operation = 'delete';

        return $this;
    }

    public function where(string|callable $column, mixed $operator = null, mixed $value = null): self
    {
        if (is_callable($column)) {
            return $this->whereNested($column, 'and');
        }

        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        return $this->addWhereClause('and', $column, $operator, $value);
    }

    public function orWhere(string|callable $column, mixed $operator = null, mixed $value = null): self
    {
        if (is_callable($column)) {
            return $this->whereNested($column, 'or');
        }

        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        return $this->addWhereClause('or', $column, $operator, $value);
    }

    public function count(string $column = '*'): int
    {
        return (int) $this->aggregate('COUNT', $column);
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function sum(string $column): int|float
    {
        return $this->numericAggregate('SUM', $column);
    }

    public function avg(string $column): int|float
    {
        return $this->numericAggregate('AVG', $column);
    }

    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    public function value(string $column): mixed
    {
        $row = (clone $this)->select([$column])->first();

        if ($row === false) {
            return null;
        }

        return $row[$column] ?? array_values($row)[0] ?? null;
    }

    /**
     * @return list<mixed>
     */
    public function pluck(string $column): array
    {
        $rows = (clone $this)->select([$column])->get();

        return array_map(
            static fn (array $row): mixed => $row[$column] ?? array_values($row)[0] ?? null,
            $rows,
        );
    }

    public function forPage(int $page, int $perPage): self
    {
        if ($page < 1) {
            throw new \InvalidArgumentException('Page must be 1 or greater.');
        }

        if ($perPage < 1) {
            throw new \InvalidArgumentException('Per-page value must be 1 or greater.');
        }

        return $this->limit($perPage)->offset(($page - 1) * $perPage);
    }

    private function addWhereClause(string $boolean, string $column, mixed $operator, mixed $value): self
    {
        $operator = strtoupper(trim((string) $operator));
        $allowedOperators = ['=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN'];

        if (! in_array($operator, $allowedOperators, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported where operator [%s].', $operator));
        }

        if (in_array($operator, ['IN', 'NOT IN'], true)) {
            if (! is_array($value) || $value === []) {
                throw new \InvalidArgumentException(sprintf('Operator [%s] expects a non-empty array.', $operator));
            }

            $placeholders = implode(', ', array_fill(0, count($value), '?'));
            $this->whereClauses[] = [
                'boolean' => $boolean === 'or' ? 'or' : 'and',
                'clause' => sprintf(
                    '%s %s (%s)',
                    $this->database->qualifyIdentifier($column),
                    $operator,
                    $placeholders,
                ),
            ];
            array_push($this->bindings, ...array_values($value));

            return $this;
        }

        $this->whereClauses[] = [
            'boolean' => $boolean === 'or' ? 'or' : 'and',
            'clause' => sprintf('%s %s ?', $this->database->qualifyIdentifier($column), $operator),
        ];
        $this->bindings[] = $value;

        return $this;
    }

    private function whereNested(callable $callback, string $boolean): self
    {
        $nested = new self($this->database, $this->table);
        $callback($nested);

        if ($nested->whereClauses === []) {
            return $this;
        }

        $this->whereClauses[] = [
            'boolean' => $boolean === 'or' ? 'or' : 'and',
            'clause' => '(' . $nested->compileWhereClauses() . ')',
        ];
        array_push($this->bindings, ...$nested->bindings);

        return $this;
    }

    public function join(string $type, string $table, string $left, string $operator, string $right): self
    {
        $joinType = strtoupper(trim($type));
        $allowedJoinTypes = ['INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS'];

        if (! in_array($joinType, $allowedJoinTypes, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported join type [%s].', $joinType));
        }

        $this->joins[] = sprintf(
            '%s JOIN %s ON %s %s %s',
            $joinType,
            $this->database->qualifyIdentifier($table),
            $this->database->qualifyIdentifier($left),
            trim($operator),
            $this->database->qualifyIdentifier($right),
        );

        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper(trim($direction));

        if (! in_array($direction, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported sort direction [%s].', $direction));
        }

        $this->orderings[] = sprintf('%s %s', $this->database->qualifyIdentifier($column), $direction);

        return $this;
    }

    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new \InvalidArgumentException('Limit must be zero or greater.');
        }

        $this->limitValue = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException('Offset must be zero or greater.');
        }

        $this->offsetValue = $offset;

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function get(): array
    {
        return $this->database->query($this->toSql(), $this->bindings());
    }

    /**
     * @return array<string, mixed>|false
     */
    public function first(): array|false
    {
        $clone = clone $this;
        $clone->limit(1);

        return $this->database->firstResult($clone->toSql(), $clone->bindings());
    }

    public function execute(): int
    {
        return $this->database->statement($this->toSql(), $this->bindings());
    }

    /**
     * @return list<mixed>
     */
    public function bindings(): array
    {
        if ($this->operation === 'update') {
            return [...array_values($this->updateData), ...$this->bindings];
        }

        return $this->bindings;
    }

    public function toSql(): string
    {
        return match ($this->operation) {
            'update' => $this->buildUpdateSql(),
            'delete' => $this->buildDeleteSql(),
            default => $this->buildSelectSql(),
        };
    }

    private function buildSelectSql(): string
    {
        $sql = sprintf(
            'SELECT %s FROM %s',
            $this->columns,
            $this->database->qualifyIdentifier($this->table),
        );

        return $this->appendClauses($sql);
    }

    private function buildUpdateSql(): string
    {
        $setClause = implode(
            ', ',
            array_map(
                fn (string $column): string => sprintf('%s = ?', $this->database->qualifyIdentifier($column)),
                array_keys($this->updateData),
            ),
        );

        $sql = sprintf(
            'UPDATE %s SET %s',
            $this->database->qualifyIdentifier($this->table),
            $setClause,
        );

        return $this->appendClauses($sql, includeOrdering: false, includeLimit: false, includeOffset: false);
    }

    private function buildDeleteSql(): string
    {
        $sql = sprintf('DELETE FROM %s', $this->database->qualifyIdentifier($this->table));

        return $this->appendClauses($sql, includeOrdering: false, includeLimit: false, includeOffset: false);
    }

    private function appendClauses(
        string $sql,
        bool $includeOrdering = true,
        bool $includeLimit = true,
        bool $includeOffset = true,
    ): string {
        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        if ($this->whereClauses !== []) {
            $sql .= ' WHERE ' . $this->compileWhereClauses();
        }

        if ($includeOrdering && $this->orderings !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderings);
        }

        if ($includeLimit && $this->limitValue !== null) {
            $sql .= sprintf(' LIMIT %d', $this->limitValue);
        }

        if ($includeOffset && $this->offsetValue !== null) {
            $sql .= sprintf(' OFFSET %d', $this->offsetValue);
        }

        return $sql;
    }

    private function compileWhereClauses(): string
    {
        $sql = '';

        foreach ($this->whereClauses as $index => $where) {
            if ($index === 0) {
                $sql .= $where['clause'];
                continue;
            }

            $sql .= ' ' . strtoupper($where['boolean']) . ' ' . $where['clause'];
        }

        return $sql;
    }

    private function aggregate(string $function, string $column): mixed
    {
        $qualified = $column === '*' ? '*' : $this->database->qualifyIdentifier($column);
        $alias = 'aggregate_value';
        $sql = sprintf(
            'SELECT %s(%s) AS %s FROM %s',
            $function,
            $qualified,
            $alias,
            $this->database->qualifyIdentifier($this->table),
        );

        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        if ($this->whereClauses !== []) {
            $sql .= ' WHERE ' . $this->compileWhereClauses();
        }

        $row = $this->database->firstResult($sql, $this->bindings());

        if ($row === false) {
            return null;
        }

        return $row[$alias] ?? null;
    }

    private function numericAggregate(string $function, string $column): int|float
    {
        $value = $this->aggregate($function, $column);

        if ($value === null) {
            return 0;
        }

        if (is_numeric($value)) {
            return str_contains((string) $value, '.') ? (float) $value : (int) $value;
        }

        throw new \RuntimeException(sprintf('Aggregate [%s] on [%s] did not return a numeric value.', $function, $column));
    }
}
