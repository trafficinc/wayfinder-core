<?php

declare(strict_types=1);

namespace Wayfinder\Console;

use Wayfinder\Database\Migrator;

final class MigrateRollbackCommand implements Command
{
    public function __construct(
        private readonly Migrator $migrator,
    ) {
    }

    public function name(): string
    {
        return 'migrate:rollback';
    }

    public function description(): string
    {
        return 'Roll back the latest migration batch.';
    }

    public function handle(array $arguments = []): int
    {
        $rolledBack = $this->migrator->rollback();

        if ($rolledBack === []) {
            fwrite(STDOUT, "No migrations to roll back.\n");

            return 0;
        }

        foreach ($rolledBack as $migration) {
            fwrite(STDOUT, sprintf("Rolled back: %s\n", $migration));
        }

        return 0;
    }
}
