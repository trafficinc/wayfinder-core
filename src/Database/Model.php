<?php

declare(strict_types=1);

namespace Wayfinder\Database;

use Wayfinder\Database\Concerns\HasAttributes;

abstract class Model
{
    use HasAttributes;

    protected bool $exists = false;

    protected static string $table;

    protected static string $primaryKey = 'id';

    /**
     * @param array<string, mixed> $attributes
     */
    public static function fromDatabaseRow(array $attributes): static
    {
        $model = new static($attributes);
        $model->exists = true;

        return $model;
    }

    public static function query(): ModelQuery
    {
        return new ModelQuery(
            DB::connection()->table(static::tableName()),
            static::class,
        );
    }

    public static function find(int|string $id): ?static
    {
        return static::query()->where(static::primaryKeyName(), $id)->first();
    }

    /**
     * @return list<static>
     */
    public static function all(): array
    {
        return static::query()->get();
    }

    public static function where(string|callable $column, mixed $operator = null, mixed $value = null): ModelQuery
    {
        if (func_num_args() === 2) {
            return static::query()->where($column, $operator);
        }

        return static::query()->where($column, $operator, $value);
    }

    public static function first(string|callable $column, mixed $operator = null, mixed $value = null): ?static
    {
        if (func_num_args() === 2) {
            return static::query()->where($column, $operator)->first();
        }

        return static::query()->where($column, $operator, $value)->first();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function create(array $attributes): static
    {
        $database = DB::connection();
        $database->insert(static::tableName(), $attributes);

        $id = $database->lastInsertId();

        if ($id !== '0' && $id !== '') {
            $created = static::find(is_numeric($id) ? (int) $id : $id);

            if ($created !== null) {
                return $created;
            }
        }

        return static::fromDatabaseRow($attributes);
    }

    public function update(array $attributes): static
    {
        if (! $this->exists) {
            throw new \RuntimeException('Cannot update a model that has not been persisted.');
        }

        DB::connection()
            ->update(static::tableName(), $attributes)
            ->where(static::primaryKeyName(), $this->getKey())
            ->execute();

        $this->fill([...$this->attributes, ...$attributes]);

        return $this;
    }

    public function delete(): bool
    {
        if (! $this->exists) {
            return false;
        }

        $deleted = DB::connection()
            ->delete(static::tableName())
            ->where(static::primaryKeyName(), $this->getKey())
            ->execute() > 0;

        if ($deleted) {
            $this->exists = false;
        }

        return $deleted;
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    public function getKey(): mixed
    {
        return $this->getAttribute(static::primaryKeyName());
    }

    public static function tableName(): string
    {
        if (! isset(static::$table) || static::$table === '') {
            throw new \RuntimeException(sprintf('Model [%s] must define a table name.', static::class));
        }

        return static::$table;
    }

    public static function primaryKeyName(): string
    {
        return static::$primaryKey;
    }
}
