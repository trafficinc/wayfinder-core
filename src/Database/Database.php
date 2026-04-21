<?php

declare(strict_types=1);

namespace Wayfinder\Database;

use PDO;
use PDOException;

final class Database
{
    private PDO $pdo;

    /**
     * @param array{
     *     driver?: string,
     *     host?: string,
     *     port?: int|string,
     *     dbname?: string,
     *     database?: string,
     *     charset?: string,
     *     username?: string,
     *     password?: string,
     *     path?: string
     * } $config
     */
    public function __construct(array $config)
    {
        $driver = $config['driver'] ?? 'mysql';
        $dsn = $this->buildDsn($driver, $config);

        try {
            $this->pdo = new PDO(
                $dsn,
                $config['username'] ?? null,
                $config['password'] ?? null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ],
            );
        } catch (PDOException $exception) {
            throw new \RuntimeException('Database connection failed.', 0, $exception);
        }
    }

    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }

    public function select(string $table, string|array $columns = '*'): QueryBuilder
    {
        return $this->table($table)->select($columns);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data = []): int|QueryBuilder
    {
        $builder = $this->table($table)->prepareInsert($data);

        if ($data === []) {
            return $builder;
        }

        return $builder->execute();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(string $table, array $data = []): QueryBuilder
    {
        return $this->table($table)->prepareUpdate($data);
    }

    public function delete(string $table): QueryBuilder
    {
        return $this->table($table)->prepareDelete();
    }

    /**
     * @param list<mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function raw(string $sql, array $bindings = []): array
    {
        return $this->query($sql, $bindings);
    }

    /**
     * @param list<mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function query(string $sql, array $bindings = []): array
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($bindings);

            return $statement->fetchAll();
        } catch (PDOException $exception) {
            throw new \RuntimeException('Database query failed.', 0, $exception);
        }
    }

    /**
     * @param list<mixed> $bindings
     */
    public function firstResult(string $sql, array $bindings = []): array|false
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($bindings);

            return $statement->fetch();
        } catch (PDOException $exception) {
            throw new \RuntimeException('Database query failed.', 0, $exception);
        }
    }

    /**
     * @param list<mixed> $bindings
     */
    public function statement(string $sql, array $bindings = []): int
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($bindings);

            return $statement->rowCount();
        } catch (PDOException $exception) {
            throw new \RuntimeException('Database statement failed.', 0, $exception);
        }
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * Execute a callback inside a database transaction.
     *
     * If a transaction is already active the callback runs within it and
     * commit/rollback are left to the outer caller. Any exception thrown
     * by the callback rolls back the transaction and is re-thrown.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $nested = $this->pdo->inTransaction();

        if (! $nested) {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $callback();

            if (! $nested) {
                $this->pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if (! $nested) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function qualifyIdentifier(string $identifier): string
    {
        $parts = explode('.', $identifier);

        foreach ($parts as $part) {
            if ($part === '*') {
                continue;
            }

            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $part)) {
                throw new \InvalidArgumentException(sprintf('Invalid SQL identifier [%s].', $identifier));
            }
        }

        return implode('.', $parts);
    }

    public function normalizeColumnList(string|array $columns): string
    {
        if (is_array($columns)) {
            return implode(', ', array_map($this->qualifyIdentifier(...), $columns));
        }

        if ($columns === '*') {
            return '*';
        }

        $parts = array_map('trim', explode(',', $columns));

        return implode(', ', array_map($this->qualifyIdentifier(...), $parts));
    }

    /**
     * @param array{
     *     host?: string,
     *     port?: int|string,
     *     dbname?: string,
     *     database?: string,
     *     charset?: string,
     *     path?: string
     * } $config
     */
    private function buildDsn(string $driver, array $config): string
    {
        return match ($driver) {
            'mysql' => sprintf(
                'mysql:host=%s;dbname=%s;charset=%s%s',
                $config['host'] ?? '127.0.0.1',
                $config['dbname'] ?? $config['database'] ?? '',
                $config['charset'] ?? 'utf8mb4',
                isset($config['port']) ? ';port=' . $config['port'] : '',
            ),
            'pgsql' => sprintf(
                'pgsql:host=%s;dbname=%s%s',
                $config['host'] ?? '127.0.0.1',
                $config['dbname'] ?? $config['database'] ?? '',
                isset($config['port']) ? ';port=' . $config['port'] : '',
            ),
            'sqlite' => sprintf('sqlite:%s', $config['path'] ?? ':memory:'),
            default => throw new \InvalidArgumentException(sprintf('Unsupported database driver [%s].', $driver)),
        };
    }
}
