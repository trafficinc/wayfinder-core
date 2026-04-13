<?php

declare(strict_types=1);

namespace Wayfinder\Database;

final class MigrationRepository
{
    public function __construct(
        private readonly Database $database,
        private readonly string $table = 'migrations',
    ) {
    }

    public function ensureExists(): void
    {
        $sql = match ($this->database->driver()) {
            'sqlite' => sprintf(
                'CREATE TABLE IF NOT EXISTS %s (migration TEXT PRIMARY KEY, batch INTEGER NOT NULL)',
                $this->table,
            ),
            default => sprintf(
                'CREATE TABLE IF NOT EXISTS %s (migration VARCHAR(255) PRIMARY KEY, batch INT NOT NULL)',
                $this->table,
            ),
        };

        $this->database->statement($sql);
    }

    /**
     * @return list<string>
     */
    public function ran(): array
    {
        $this->ensureExists();

        return array_map(
            static fn (array $row): string => (string) $row['migration'],
            $this->database->query(sprintf('SELECT migration FROM %s ORDER BY migration ASC', $this->table)),
        );
    }

    public function nextBatchNumber(): int
    {
        $this->ensureExists();

        $row = $this->database->query(sprintf('SELECT MAX(batch) AS batch FROM %s', $this->table))[0] ?? null;

        return ((int) ($row['batch'] ?? 0)) + 1;
    }

    public function log(string $migration, int $batch): void
    {
        $this->database->insert($this->table, [
            'migration' => $migration,
            'batch' => $batch,
        ]);
    }

    public function delete(string $migration): void
    {
        $this->database
            ->delete($this->table)
            ->where('migration', '=', $migration)
            ->execute();
    }

    public function lastBatchNumber(): int
    {
        $this->ensureExists();

        $row = $this->database->query(sprintf('SELECT MAX(batch) AS batch FROM %s', $this->table))[0] ?? null;

        return (int) ($row['batch'] ?? 0);
    }

    /**
     * @return list<string>
     */
    public function lastBatchMigrations(): array
    {
        $batch = $this->lastBatchNumber();

        if ($batch === 0) {
            return [];
        }

        return array_map(
            static fn (array $row): string => (string) $row['migration'],
            $this->database->query(
                sprintf('SELECT migration FROM %s WHERE batch = ? ORDER BY migration DESC', $this->table),
                [$batch],
            ),
        );
    }

    /**
     * @return list<string>
     */
    public function allRanDescending(): array
    {
        $this->ensureExists();

        return array_map(
            static fn (array $row): string => (string) $row['migration'],
            $this->database->query(
                sprintf('SELECT migration FROM %s ORDER BY batch DESC, migration DESC', $this->table),
            ),
        );
    }

    /**
     * @return list<array{migration: string, batch: int}>
     */
    public function status(): array
    {
        $this->ensureExists();

        return array_map(
            static fn (array $row): array => [
                'migration' => (string) $row['migration'],
                'batch' => (int) $row['batch'],
            ],
            $this->database->query(sprintf('SELECT migration, batch FROM %s ORDER BY batch ASC, migration ASC', $this->table)),
        );
    }
}
