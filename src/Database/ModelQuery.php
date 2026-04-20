<?php

declare(strict_types=1);

namespace Wayfinder\Database;

/**
 * @template TModel of Model
 */
final class ModelQuery
{
    /**
     * @param class-string<TModel> $modelClass
     */
    public function __construct(
        private readonly QueryBuilder $builder,
        private readonly string $modelClass,
    ) {
    }

    public function where(string|callable $column, mixed $operator = null, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $this->builder->where($column, $operator);

            return $this;
        }

        $this->builder->where($column, $operator, $value);

        return $this;
    }

    public function orWhere(string|callable $column, mixed $operator = null, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $this->builder->orWhere($column, $operator);

            return $this;
        }

        $this->builder->orWhere($column, $operator, $value);

        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->builder->orderBy($column, $direction);

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->builder->limit($limit);

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->builder->offset($offset);

        return $this;
    }

    /**
     * @return list<TModel>
     */
    public function get(): array
    {
        return array_map(
            fn (array $row): Model => $this->modelClass::fromDatabaseRow($row),
            $this->builder->get(),
        );
    }

    /**
     * @return TModel|null
     */
    public function first(): ?Model
    {
        $row = $this->builder->first();

        if ($row === false) {
            return null;
        }

        return $this->modelClass::fromDatabaseRow($row);
    }

    public function count(string $column = '*'): int
    {
        return $this->builder->count($column);
    }

    public function exists(): bool
    {
        return $this->builder->exists();
    }

    public function value(string $column): mixed
    {
        return $this->builder->value($column);
    }

    /**
     * @return list<mixed>
     */
    public function pluck(string $column): array
    {
        return $this->builder->pluck($column);
    }
}
