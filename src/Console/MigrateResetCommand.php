<?php

declare(strict_types=1);

namespace Wayfinder\Console;

use Wayfinder\Database\Migrator;

final class MigrateResetCommand implements Command
{
    public function __construct(
        private readonly Migrator $migrator,
    ) {
    }

    public function name(): string
    {
        return 'migrate:reset';
    }

    public function description(): string
    {
        return 'Roll back all migrations.';
    }

    public function handle(array $arguments = []): int
    {
        $rolledBack = $this->migrator->reset();

        if ($rolledBack === []) {
            fwrite(STDOUT, "No migrations to reset.\n");

            return 0;
        }

        foreach ($rolledBack as $migration) {
            fwrite(STDOUT, sprintf("Reset: %s\n", $migration));
        }

        return 0;
    }
}
