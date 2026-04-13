<?php

declare(strict_types=1);

namespace Wayfinder\Database;

final class Migrator
{
    /**
     * @var list<string>
     */
    private array $paths;

    public function __construct(
        private readonly Database $database,
        private readonly MigrationRepository $repository,
        string|array $path,
    ) {
        $this->paths = is_array($path) ? array_values(array_filter($path, 'is_string')) : [$path];
    }

    /**
     * @return list<string>
     */
    public function pending(): array
    {
        $files = $this->migrationFiles();
        $ran = array_flip($this->repository->ran());

        return array_values(array_filter(
            array_keys($files),
            static fn (string $migration): bool => ! isset($ran[$migration]),
        ));
    }

    /**
     * @return list<string>
     */
    public function run(): array
    {
        $this->repository->ensureExists();

        $files = $this->migrationFiles();
        $pending = $this->pending();

        if ($pending === []) {
            return [];
        }

        $batch = $this->repository->nextBatchNumber();
        $ran = [];

        foreach ($pending as $name) {
            $migration = $this->resolveMigration($files[$name]);
            $this->database->beginTransaction();

            try {
                $migration->up($this->database);
                $this->repository->log($name, $batch);
                $this->database->commit();
                $ran[] = $name;
            } catch (\Throwable $throwable) {
                if ($this->database->inTransaction()) {
                    $this->database->rollBack();
                }

                throw $throwable;
            }
        }

        return $ran;
    }

    /**
     * @return list<string>
     */
    public function rollback(): array
    {
        $this->repository->ensureExists();

        $files = $this->migrationFiles();
        $rolledBack = [];

        foreach ($this->repository->lastBatchMigrations() as $name) {
            $rolledBack[] = $this->rollbackMigration($name, $files);
        }

        return $rolledBack;
    }

    /**
     * @return list<string>
     */
    public function reset(): array
    {
        $this->repository->ensureExists();

        $files = $this->migrationFiles();
        $rolledBack = [];

        foreach ($this->repository->allRanDescending() as $name) {
            $rolledBack[] = $this->rollbackMigration($name, $files);
        }

        return $rolledBack;
    }

    /**
     * @return array{rolled_back: list<string>, migrated: list<string>}
     */
    public function refresh(): array
    {
        $rolledBack = $this->reset();
        $migrated = $this->run();

        return [
            'rolled_back' => $rolledBack,
            'migrated' => $migrated,
        ];
    }

    /**
     * @return list<array{migration: string, batch: int, status: string}>
     */
    public function status(): array
    {
        $files = array_keys($this->migrationFiles());
        $ran = [];

        foreach ($this->repository->status() as $row) {
            $ran[$row['migration']] = $row['batch'];
        }

        $status = [];

        foreach ($files as $file) {
            $status[] = [
                'migration' => $file,
                'batch' => (int) ($ran[$file] ?? 0),
                'status' => isset($ran[$file]) ? 'ran' : 'pending',
            ];
        }

        return $status;
    }

    /**
     * @param array<string, string> $files
     */
    private function rollbackMigration(string $name, array $files): string
    {
        if (! isset($files[$name])) {
            throw new \RuntimeException(sprintf('Migration file for [%s] was not found.', $name));
        }

        $migration = $this->resolveMigration($files[$name]);
        $this->database->beginTransaction();

        try {
            $migration->down($this->database);
            $this->repository->delete($name);
            $this->database->commit();

            return $name;
        } catch (\Throwable $throwable) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }

            throw $throwable;
        }
    }

    /**
     * @return array<string, string>
     */
    private function migrationFiles(): array
    {
        $files = [];

        foreach ($this->paths as $path) {
            foreach (glob(rtrim($path, '/') . '/*.php') ?: [] as $file) {
                $name = basename($file, '.php');

                if (isset($files[$name]) && $files[$name] !== $file) {
                    throw new \RuntimeException(sprintf(
                        'Duplicate migration name [%s] found at [%s] and [%s].',
                        $name,
                        $files[$name],
                        $file,
                    ));
                }

                $files[$name] = $file;
            }
        }

        ksort($files);

        return $files;
    }

    private function resolveMigration(string $file): Migration
    {
        $migration = require $file;

        if (! $migration instanceof Migration) {
            throw new \RuntimeException(sprintf('Migration [%s] must return a %s instance.', $file, Migration::class));
        }

        return $migration;
    }
}
