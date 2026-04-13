<?php

declare(strict_types=1);

namespace Wayfinder\Console;

use Wayfinder\Database\Migrator;

final class MigrateRefreshCommand implements Command
{
    public function __construct(
        private readonly Migrator $migrator,
    ) {
    }

    public function name(): string
    {
        return 'migrate:refresh';
    }

    public function description(): string
    {
        return 'Reset all migrations and run them again.';
    }

    public function handle(array $arguments = []): int
    {
        $result = $this->migrator->refresh();

        foreach ($result['rolled_back'] as $migration) {
            fwrite(STDOUT, sprintf("Reset: %s\n", $migration));
        }

        foreach ($result['migrated'] as $migration) {
            fwrite(STDOUT, sprintf("Migrated: %s\n", $migration));
        }

        if ($result['rolled_back'] === [] && $result['migrated'] === []) {
            fwrite(STDOUT, "No migrations found.\n");
        }

        return 0;
    }
}
