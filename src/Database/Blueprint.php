<?php

declare(strict_types=1);

namespace Wayfinder\Database;

final class Blueprint
{
    /** @var list<ColumnDefinition> */
    private array $columns = [];

    /** @var list<array<string, mixed>> */
    private array $commands = [];

    // ---- Column types ----

    public function id(string $column = 'id'): ColumnDefinition
    {
        $col = new ColumnDefinition($column, 'bigInteger');
        $col->unsigned = true;
        $col->autoIncrement = true;
        $col->primaryKey = true;
        $this->columns[] = $col;

        return $col;
    }

    public function string(string $column, int $length = 255): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'string', length: $length));
    }

    public function text(string $column): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'text'));
    }

    public function longText(string $column): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'longText'));
    }

    public function integer(string $column): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'integer'));
    }

    public function tinyInteger(string $column): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'tinyInteger'));
    }

    public function smallInteger(string $column): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'smallInteger'));
    }

    public function bigInteger(string $column): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'bigInteger'));
    }

    public function unsignedInteger(string $column): ColumnDefinition
    {
        $col = new ColumnDefinition($column, 'integer');
        $col->unsigned = true;

        return $this->addColumn($col);
    }

    public function unsignedBigInteger(string $column): ColumnDefinition
    {
        $col = new ColumnDefinition($column, 'bigInteger');
        $col->unsigned = true;

        return $this->addColumn($col);
    }

    public function boolean(string $column): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'boolean'));
    }

    public function decimal(string $column, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'decimal', precision: $precision, scale: $scale));
    }

    public function float(string $column): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'float'));
    }

    public function double(string $column): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'double'));
    }

    public function date(string $column): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'date'));
    }

    public function dateTime(string $column): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'dateTime'));
    }

    public function timestamp(string $column): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'timestamp'));
    }

    public function timestamps(): void
    {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();
    }

    public function softDeletes(string $column = 'deleted_at'): ColumnDefinition
    {
        return $this->timestamp($column)->nullable();
    }

    public function json(string $column): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'json'));
    }

    public function uuid(string $column): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'uuid'));
    }

    /**
     * @param list<string> $allowed
     */
    public function enum(string $column, array $allowed): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'enum', allowed: $allowed));
    }

    public function binary(string $column): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'binary'));
    }

    /**
     * BIGINT UNSIGNED foreign key column (no constraint — add via raw SQL if needed).
     */
    public function foreignId(string $column): ColumnDefinition
    {
        $col = new ColumnDefinition($column, 'bigInteger');
        $col->unsigned = true;

        return $this->addColumn($col);
    }

    // ---- Index commands ----

    /**
     * @param string|list<string> $columns
     */
    public function primary(string|array $columns): void
    {
        $this->commands[] = ['type' => 'primary', 'columns' => (array) $columns];
    }

    /**
     * @param string|list<string> $columns
     */
    public function unique(string|array $columns, ?string $name = null): void
    {
        $this->commands[] = ['type' => 'unique', 'columns' => (array) $columns, 'name' => $name];
    }

    /**
     * @param string|list<string> $columns
     */
    public function index(string|array $columns, ?string $name = null): void
    {
        $this->commands[] = ['type' => 'index', 'columns' => (array) $columns, 'name' => $name];
    }

    // ---- Alter commands ----

    /**
     * @param string|list<string> $columns
     */
    public function dropColumn(string|array $columns): void
    {
        $this->commands[] = ['type' => 'dropColumn', 'columns' => (array) $columns];
    }

    public function renameColumn(string $from, string $to): void
    {
        $this->commands[] = ['type' => 'renameColumn', 'from' => $from, 'to' => $to];
    }

    public function dropIndex(string $name): void
    {
        $this->commands[] = ['type' => 'dropIndex', 'name' => $name];
    }

    public function dropUnique(string $name): void
    {
        $this->commands[] = ['type' => 'dropIndex', 'name' => $name];
    }

    public function dropPrimary(?string $name = null): void
    {
        $this->commands[] = ['type' => 'dropPrimary', 'name' => $name];
    }

    // ---- Accessors ----

    /**
     * @return list<ColumnDefinition>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    private function addColumn(ColumnDefinition $column): ColumnDefinition
    {
        $this->columns[] = $column;

        return $column;
    }
}
