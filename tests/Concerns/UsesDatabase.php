<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Concerns;

use Wayfinder\Database\DB;
use Wayfinder\Database\Database;

trait UsesDatabase
{
    private Database $db;

    protected function setUpDatabase(): void
    {
        $this->db = new Database(['driver' => 'sqlite', 'path' => ':memory:']);
        DB::setResolver(fn (): Database => $this->db);

        $this->db->statement('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            password TEXT,
            is_admin INTEGER DEFAULT 0,
            nickname TEXT NULL
        )');

        $this->db->statement('CREATE TABLE profiles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            display_name TEXT NOT NULL
        )');
    }

    protected function tearDownDatabase(): void
    {
        DB::setResolver(static fn () => throw new \RuntimeException('DB resolver not configured.'));
    }
}
