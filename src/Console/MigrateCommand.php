<?php

declare(strict_types=1);

namespace Wayfinder\Console;

use Wayfinder\Database\Migrator;

final class MigrateCommand implements Command
{
    public function __construct(
        private readonly Migrator $migrator,
    ) {
    }

    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'Run all pending database migrations.';
    }

    public function handle(array $arguments = []): int
    {
        $ran = $this->migrator->run();

        if ($ran === []) {
            fwrite(STDOUT, "No pending migrations.\n");

            return 0;
        }

        foreach ($ran as $migration) {
            fwrite(STDOUT, sprintf("Migrated: %s\n", $migration));
        }

        return 0;
    }
}
