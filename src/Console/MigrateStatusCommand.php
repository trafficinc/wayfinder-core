<?php

declare(strict_types=1);

namespace Wayfinder\Console;

use Wayfinder\Database\Migrator;

final class MigrateStatusCommand implements Command
{
    public function __construct(
        private readonly Migrator $migrator,
    ) {
    }

    public function name(): string
    {
        return 'migrate:status';
    }

    public function description(): string
    {
        return 'Show migration status.';
    }

    public function handle(array $arguments = []): int
    {
        $rows = $this->migrator->status();

        if ($rows === []) {
            fwrite(STDOUT, "No migration files found.\n");

            return 0;
        }

        foreach ($rows as $row) {
            fwrite(STDOUT, sprintf(
                "[%s] batch=%d %s\n",
                strtoupper($row['status']),
                $row['batch'],
                $row['migration'],
            ));
        }

        return 0;
    }
}
