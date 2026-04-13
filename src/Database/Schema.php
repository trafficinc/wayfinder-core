<?php

declare(strict_types=1);

namespace Wayfinder\Database;

final class Schema
{
    public static function create(string $table, callable $callback): void
    {
        $db = DB::connection();
        $blueprint = new Blueprint();
        $callback($blueprint);

        foreach ((new SchemaGrammar($db->driver()))->compileCreate($table, $blueprint) as $sql) {
            $db->statement($sql);
        }
    }

    public static function table(string $table, callable $callback): void
    {
        $db = DB::connection();
        $blueprint = new Blueprint();
        $callback($blueprint);

        foreach ((new SchemaGrammar($db->driver()))->compileAlter($table, $blueprint) as $sql) {
            $db->statement($sql);
        }
    }

    public static function drop(string $table): void
    {
        $db = DB::connection();
        $db->statement((new SchemaGrammar($db->driver()))->compileDrop($table));
    }

    public static function dropIfExists(string $table): void
    {
        $db = DB::connection();
        $db->statement((new SchemaGrammar($db->driver()))->compileDropIfExists($table));
    }

    public static function rename(string $from, string $to): void
    {
        $db = DB::connection();
        $db->statement((new SchemaGrammar($db->driver()))->compileRenameTable($from, $to));
    }

    public static function hasTable(string $table): bool
    {
        $db = DB::connection();
        [$sql, $bindings] = (new SchemaGrammar($db->driver()))->compileHasTable($table);
        $result = $db->query($sql, $bindings);

        return (int) ($result[0]['count'] ?? 0) > 0;
    }

    public static function hasColumn(string $table, string $column): bool
    {
        $db = DB::connection();
        [$sql, $bindings] = (new SchemaGrammar($db->driver()))->compileHasColumn($table, $column);
        $result = $db->query($sql, $bindings);

        return (int) ($result[0]['count'] ?? 0) > 0;
    }
}
