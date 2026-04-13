<?php

declare(strict_types=1);

namespace Wayfinder\Database;

final class SchemaGrammar
{
    public function __construct(private readonly string $driver)
    {
    }

    /**
     * Compile a CREATE TABLE statement (plus any trailing CREATE INDEX statements).
     *
     * @return list<string>
     */
    public function compileCreate(string $table, Blueprint $blueprint): array
    {
        $tableQ = $this->q($table);
        $columnParts = [];
        $tableConstraints = [];

        foreach ($blueprint->getColumns() as $col) {
            $columnParts[] = $this->compileColumnDefinition($col);

            // SQLite uses inline INTEGER PRIMARY KEY AUTOINCREMENT — no separate constraint.
            // MySQL and PostgreSQL need a table-level PRIMARY KEY clause.
            if ($col->autoIncrement && $col->primaryKey && $this->driver !== 'sqlite') {
                $tableConstraints[] = sprintf('PRIMARY KEY (%s)', $this->q($col->name));
            }
        }

        // Explicit primary() commands
        foreach ($blueprint->getCommands() as $command) {
            if ($command['type'] === 'primary') {
                $cols = implode(', ', array_map($this->q(...), $command['columns']));
                $tableConstraints[] = sprintf('PRIMARY KEY (%s)', $cols);
            }
        }

        $body = implode(",\n    ", array_merge($columnParts, $tableConstraints));
        $sql = sprintf("CREATE TABLE %s (\n    %s\n)", $tableQ, $body);

        if ($this->driver === 'mysql') {
            $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }

        $statements = [$sql];

        // Column-level ->unique() modifiers → separate CREATE UNIQUE INDEX
        foreach ($blueprint->getColumns() as $col) {
            if ($col->uniqueKey) {
                $name = $table . '_' . $col->name . '_unique';
                $statements[] = sprintf(
                    'CREATE UNIQUE INDEX %s ON %s (%s)',
                    $this->q($name), $tableQ, $this->q($col->name),
                );
            }
        }

        // unique() and index() commands
        return array_merge($statements, $this->compileIndexCommands($table, $blueprint));
    }

    /**
     * Compile ALTER TABLE statements for an existing table.
     *
     * @return list<string>
     */
    public function compileAlter(string $table, Blueprint $blueprint): array
    {
        $statements = [];
        $tableQ = $this->q($table);

        foreach ($blueprint->getColumns() as $col) {
            if ($col->change) {
                $statements[] = match ($this->driver) {
                    'mysql' => sprintf('ALTER TABLE %s MODIFY COLUMN %s', $tableQ, $this->compileColumnDefinition($col)),
                    'pgsql' => $this->compilePgsqlAlterColumn($table, $col),
                    default => throw new \RuntimeException('Column modification via change() is only supported on MySQL and PostgreSQL.'),
                };
            } else {
                $statements[] = sprintf('ALTER TABLE %s ADD COLUMN %s', $tableQ, $this->compileColumnDefinition($col));
            }
        }

        foreach ($blueprint->getCommands() as $command) {
            array_push($statements, ...$this->compileAlterCommand($table, $command));
        }

        return $statements;
    }

    public function compileDrop(string $table): string
    {
        return sprintf('DROP TABLE %s', $this->q($table));
    }

    public function compileDropIfExists(string $table): string
    {
        return sprintf('DROP TABLE IF EXISTS %s', $this->q($table));
    }

    public function compileRenameTable(string $from, string $to): string
    {
        return match ($this->driver) {
            'mysql' => sprintf('RENAME TABLE %s TO %s', $this->q($from), $this->q($to)),
            default => sprintf('ALTER TABLE %s RENAME TO %s', $this->q($from), $this->q($to)),
        };
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    public function compileHasTable(string $table): array
    {
        return match ($this->driver) {
            'mysql' => [
                'SELECT COUNT(*) AS count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                [$table],
            ],
            'pgsql' => [
                'SELECT COUNT(*) AS count FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?',
                [$table],
            ],
            default => [
                "SELECT COUNT(*) AS count FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table],
            ],
        };
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    public function compileHasColumn(string $table, string $column): array
    {
        return match ($this->driver) {
            'mysql' => [
                'SELECT COUNT(*) AS count FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$table, $column],
            ],
            'pgsql' => [
                'SELECT COUNT(*) AS count FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
                [$table, $column],
            ],
            default => [
                'SELECT COUNT(*) AS count FROM pragma_table_info(?) WHERE name = ?',
                [$table, $column],
            ],
        };
    }

    private function compileColumnDefinition(ColumnDefinition $col): string
    {
        // Auto-increment primary keys require driver-specific syntax
        if ($col->autoIncrement && $col->primaryKey) {
            return match ($this->driver) {
                'sqlite' => $this->q($col->name) . ' INTEGER PRIMARY KEY AUTOINCREMENT',
                'pgsql'  => $this->q($col->name) . ' ' . ($col->type === 'integer' ? 'SERIAL' : 'BIGSERIAL'),
                default  => $this->q($col->name) . ' BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
            };
        }

        $parts = [$this->q($col->name), $this->getType($col)];

        if ($col->unsigned && $this->driver === 'mysql') {
            $parts[] = 'UNSIGNED';
        }

        $parts[] = $col->nullable ? 'NULL' : 'NOT NULL';

        if ($col->hasDefault) {
            $parts[] = 'DEFAULT ' . $this->compileDefaultValue($col->defaultValue);
        }

        // Non-MySQL enum: TEXT + inline CHECK constraint
        if ($col->type === 'enum' && $this->driver !== 'mysql' && $col->allowed !== []) {
            $values = implode(', ', array_map(
                fn (string $v): string => "'" . str_replace("'", "''", $v) . "'",
                $col->allowed,
            ));
            $parts[] = sprintf('CHECK (%s IN (%s))', $this->q($col->name), $values);
        }

        // MySQL AFTER clause for column ordering in ALTER TABLE
        if ($col->after !== null && $this->driver === 'mysql') {
            $parts[] = 'AFTER ' . $this->q($col->after);
        }

        return implode(' ', $parts);
    }

    private function getType(ColumnDefinition $col): string
    {
        return match ($col->type) {
            'string'       => sprintf('VARCHAR(%d)', $col->length ?? 255),
            'text'         => 'TEXT',
            'longText'     => $this->driver === 'mysql' ? 'LONGTEXT' : 'TEXT',
            'integer'      => $this->driver === 'mysql' ? 'INT' : 'INTEGER',
            'tinyInteger'  => match ($this->driver) {
                'mysql'  => 'TINYINT',
                'pgsql'  => 'SMALLINT',
                default  => 'INTEGER',
            },
            'smallInteger' => match ($this->driver) {
                'sqlite' => 'INTEGER',
                default  => 'SMALLINT',
            },
            'bigInteger'   => $this->driver === 'sqlite' ? 'INTEGER' : 'BIGINT',
            'boolean'      => match ($this->driver) {
                'mysql'  => 'TINYINT(1)',
                'pgsql'  => 'BOOLEAN',
                default  => 'INTEGER',
            },
            'decimal'      => sprintf('DECIMAL(%d, %d)', $col->precision ?? 8, $col->scale ?? 2),
            'float'        => $this->driver === 'pgsql' ? 'REAL' : 'FLOAT',
            'double'       => match ($this->driver) {
                'pgsql'  => 'DOUBLE PRECISION',
                'sqlite' => 'REAL',
                default  => 'DOUBLE',
            },
            'date'         => $this->driver === 'sqlite' ? 'TEXT' : 'DATE',
            'dateTime'     => match ($this->driver) {
                'mysql'  => 'DATETIME',
                'pgsql'  => 'TIMESTAMP(0) WITHOUT TIME ZONE',
                default  => 'TEXT',
            },
            'timestamp'    => match ($this->driver) {
                'mysql'  => 'TIMESTAMP',
                'pgsql'  => 'TIMESTAMP(0) WITHOUT TIME ZONE',
                default  => 'TEXT',
            },
            'json'         => match ($this->driver) {
                'mysql'  => 'JSON',
                'pgsql'  => 'JSONB',
                default  => 'TEXT',
            },
            'uuid'         => match ($this->driver) {
                'mysql'  => 'CHAR(36)',
                'pgsql'  => 'UUID',
                default  => 'TEXT',
            },
            'enum'         => $this->driver === 'mysql'
                ? 'ENUM(' . implode(', ', array_map(
                    fn (string $v): string => "'" . str_replace("'", "''", $v) . "'",
                    $col->allowed,
                )) . ')'
                : 'TEXT',
            'binary'       => $this->driver === 'pgsql' ? 'BYTEA' : 'BLOB',
            default        => throw new \InvalidArgumentException(sprintf('Unknown column type [%s].', $col->type)),
        };
    }

    private function compileDefaultValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            if ($this->driver === 'pgsql') {
                return $value ? 'TRUE' : 'FALSE';
            }

            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    /**
     * @return list<string>
     */
    private function compileIndexCommands(string $table, Blueprint $blueprint): array
    {
        $tableQ = $this->q($table);
        $statements = [];

        foreach ($blueprint->getCommands() as $command) {
            if ($command['type'] === 'unique') {
                $name = $command['name'] ?? $table . '_' . implode('_', $command['columns']) . '_unique';
                $cols = implode(', ', array_map($this->q(...), $command['columns']));
                $statements[] = sprintf('CREATE UNIQUE INDEX %s ON %s (%s)', $this->q($name), $tableQ, $cols);
            } elseif ($command['type'] === 'index') {
                $name = $command['name'] ?? $table . '_' . implode('_', $command['columns']) . '_index';
                $cols = implode(', ', array_map($this->q(...), $command['columns']));
                $statements[] = sprintf('CREATE INDEX %s ON %s (%s)', $this->q($name), $tableQ, $cols);
            }
        }

        return $statements;
    }

    /**
     * @param array<string, mixed> $command
     * @return list<string>
     */
    private function compileAlterCommand(string $table, array $command): array
    {
        $tableQ = $this->q($table);

        return match ($command['type']) {
            'dropColumn' => array_map(
                fn (string $col): string => sprintf('ALTER TABLE %s DROP COLUMN %s', $tableQ, $this->q($col)),
                $command['columns'],
            ),
            'renameColumn' => [sprintf(
                'ALTER TABLE %s RENAME COLUMN %s TO %s',
                $tableQ, $this->q($command['from']), $this->q($command['to']),
            )],
            'dropIndex' => [$this->driver === 'mysql'
                ? sprintf('ALTER TABLE %s DROP INDEX %s', $tableQ, $this->q($command['name']))
                : sprintf('DROP INDEX %s', $this->q($command['name']))],
            'dropPrimary' => [$this->driver === 'mysql'
                ? sprintf('ALTER TABLE %s DROP PRIMARY KEY', $tableQ)
                : sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $tableQ, $this->q($command['name'] ?? $table . '_pkey'))],
            'primary' => [sprintf(
                'ALTER TABLE %s ADD PRIMARY KEY (%s)',
                $tableQ, implode(', ', array_map($this->q(...), $command['columns'])),
            )],
            'unique' => (function () use ($table, $tableQ, $command): array {
                $name = $command['name'] ?? $table . '_' . implode('_', $command['columns']) . '_unique';
                $cols = implode(', ', array_map($this->q(...), $command['columns']));
                return [sprintf('CREATE UNIQUE INDEX %s ON %s (%s)', $this->q($name), $tableQ, $cols)];
            })(),
            'index' => (function () use ($table, $tableQ, $command): array {
                $name = $command['name'] ?? $table . '_' . implode('_', $command['columns']) . '_index';
                $cols = implode(', ', array_map($this->q(...), $command['columns']));
                return [sprintf('CREATE INDEX %s ON %s (%s)', $this->q($name), $tableQ, $cols)];
            })(),
            default => [],
        };
    }

    /**
     * PostgreSQL combines multiple ALTER COLUMN clauses in a single statement.
     */
    private function compilePgsqlAlterColumn(string $table, ColumnDefinition $col): string
    {
        $tableQ = $this->q($table);
        $colQ = $this->q($col->name);
        $type = $this->getType($col);

        $alterations = [
            sprintf('ALTER COLUMN %s TYPE %s', $colQ, $type),
            $col->nullable
                ? sprintf('ALTER COLUMN %s DROP NOT NULL', $colQ)
                : sprintf('ALTER COLUMN %s SET NOT NULL', $colQ),
        ];

        if ($col->hasDefault) {
            $alterations[] = sprintf(
                'ALTER COLUMN %s SET DEFAULT %s',
                $colQ, $this->compileDefaultValue($col->defaultValue),
            );
        }

        return sprintf('ALTER TABLE %s %s', $tableQ, implode(', ', $alterations));
    }

    private function q(string $identifier): string
    {
        return match ($this->driver) {
            'mysql' => '`' . str_replace('`', '``', $identifier) . '`',
            default => '"' . str_replace('"', '""', $identifier) . '"',
        };
    }
}
