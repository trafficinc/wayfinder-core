<?php

declare(strict_types=1);

namespace Wayfinder\Console;

final class MakeQueueTableCommand implements Command
{
    public function __construct(
        private readonly string $path,
        private readonly string $table = 'jobs',
    ) {
    }

    public function name(): string
    {
        return 'make:queue-table';
    }

    public function description(): string
    {
        return 'Create a migration for the queue jobs table.';
    }

    public function handle(array $arguments = []): int
    {
        if (! is_dir($this->path) && ! mkdir($this->path, 0777, true) && ! is_dir($this->path)) {
            fwrite(STDERR, sprintf("Unable to create migration directory [%s].\n", $this->path));

            return 1;
        }

        $normalized = sprintf('create_%s_table', $this->table);
        $existing = glob(rtrim($this->path, '/') . '/*_' . $normalized . '.php') ?: [];

        if ($existing !== []) {
            fwrite(STDERR, "Queue table migration already exists.\n");

            return 1;
        }

        $filename = sprintf('%s_%s.php', date('YmdHis'), $normalized);
        $target = rtrim($this->path, '/') . '/' . $filename;

        $template = str_replace(
            ['{{table}}'],
            [$this->table],
            <<<'PHP'
<?php

declare(strict_types=1);

use Wayfinder\Database\Database;
use Wayfinder\Database\Migration;

return new class implements Migration
{
    public function up(Database $database): void
    {
        $driver = $database->driver();

        if ($driver === 'mysql') {
            $database->statement(<<<'SQL'
                CREATE TABLE {{table}} (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    job VARCHAR(255) NOT NULL,
                    payload LONGTEXT NOT NULL,
                    attempts INTEGER NOT NULL DEFAULT 0,
                    status VARCHAR(32) NOT NULL,
                    queued_at TIMESTAMP NULL,
                    processing_started_at TIMESTAMP NULL,
                    failed_at TIMESTAMP NULL,
                    error TEXT NULL,
                    INDEX {{table}}_status_index (status),
                    INDEX {{table}}_processing_started_at_index (processing_started_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

            return;
        }

        if ($driver === 'pgsql') {
            $database->statement(<<<'SQL'
                CREATE TABLE {{table}} (
                    id BIGSERIAL PRIMARY KEY,
                    job VARCHAR(255) NOT NULL,
                    payload TEXT NOT NULL,
                    attempts INTEGER NOT NULL DEFAULT 0,
                    status VARCHAR(32) NOT NULL,
                    queued_at TIMESTAMP(0) WITHOUT TIME ZONE NULL,
                    processing_started_at TIMESTAMP(0) WITHOUT TIME ZONE NULL,
                    failed_at TIMESTAMP(0) WITHOUT TIME ZONE NULL,
                    error TEXT NULL
                )
            SQL);
            $database->statement('CREATE INDEX {{table}}_status_index ON {{table}} (status)');
            $database->statement('CREATE INDEX {{table}}_processing_started_at_index ON {{table}} (processing_started_at)');

            return;
        }

        $database->statement(<<<'SQL'
            CREATE TABLE {{table}} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job TEXT NOT NULL,
                payload TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL,
                queued_at TEXT NULL,
                processing_started_at TEXT NULL,
                failed_at TEXT NULL,
                error TEXT NULL
            )
        SQL);
        $database->statement('CREATE INDEX {{table}}_status_index ON {{table}} (status)');
        $database->statement('CREATE INDEX {{table}}_processing_started_at_index ON {{table}} (processing_started_at)');
    }

    public function down(Database $database): void
    {
        $database->statement('DROP TABLE IF EXISTS {{table}}');
    }
};
PHP,
        );

        if (file_put_contents($target, $template . "\n") === false) {
            fwrite(STDERR, sprintf("Unable to write migration [%s].\n", $filename));

            return 1;
        }

        fwrite(STDOUT, sprintf("Created queue table migration: %s\n", $filename));

        return 0;
    }
}
