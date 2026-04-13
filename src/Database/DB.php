<?php

declare(strict_types=1);

namespace Wayfinder\Database;

final class DB
{
    /**
     * @var callable(): Database|null
     */
    private static $resolver = null;

    /**
     * @param callable(): Database $resolver
     */
    public static function setResolver(callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function connection(): Database
    {
        if (self::$resolver === null) {
            throw new \RuntimeException('No database resolver has been configured.');
        }

        return (self::$resolver)();
    }

    public static function table(string $table): QueryBuilder
    {
        return self::connection()->table($table);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        return self::connection()->transaction($callback);
    }

    /**
     * @param list<mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public static function raw(string $sql, array $bindings = []): array
    {
        return self::connection()->raw($sql, $bindings);
    }

    public static function __callStatic(string $method, array $arguments): mixed
    {
        return self::connection()->{$method}(...$arguments);
    }
}
