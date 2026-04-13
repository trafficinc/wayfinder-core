<?php

declare(strict_types=1);

namespace Wayfinder\Console;

final class MakeMigrationCommand implements Command
{
    public function __construct(
        private readonly string $path,
    ) {
    }

    public function name(): string
    {
        return 'make:migration';
    }

    public function description(): string
    {
        return 'Create a new migration file.';
    }

    public function handle(array $arguments = []): int
    {
        $name = $arguments[0] ?? null;

        if (! is_string($name) || trim($name) === '') {
            fwrite(STDERR, "Usage: php wayfinder make:migration <name>\n");

            return 1;
        }

        $normalized = $this->normalizeName($name);
        $timestamp = date('YmdHis');
        $filename = sprintf('%s_%s.php', $timestamp, $normalized);
        $target = rtrim($this->path, '/') . '/' . $filename;

        if (! is_dir($this->path) && ! mkdir($this->path, 0777, true) && ! is_dir($this->path)) {
            fwrite(STDERR, sprintf("Unable to create migration directory [%s].\n", $this->path));

            return 1;
        }

        if (file_exists($target)) {
            fwrite(STDERR, sprintf("Migration [%s] already exists.\n", $filename));

            return 1;
        }

        $template = $this->buildTemplate($normalized);

        if (file_put_contents($target, $template . "\n") === false) {
            fwrite(STDERR, sprintf("Unable to write migration [%s].\n", $filename));

            return 1;
        }

        fwrite(STDOUT, sprintf("Created migration: %s\n", $filename));

        return 0;
    }

    private function buildTemplate(string $normalized): string
    {
        // Detect create_<table> pattern
        if (preg_match('/^create_(.+)_table$/', $normalized, $m) || preg_match('/^create_(.+)$/', $normalized, $m)) {
            $table = $m[1];

            return <<<PHP
<?php

declare(strict_types=1);

use Wayfinder\Database\Database;
use Wayfinder\Database\Migration;
use Wayfinder\Database\Schema;

return new class implements Migration
{
    public function up(Database \$database): void
    {
        Schema::create('{$table}', function (\\Wayfinder\\Database\\Blueprint \$table) {
            \$table->id();
            \$table->timestamps();
        });
    }

    public function down(Database \$database): void
    {
        Schema::dropIfExists('{$table}');
    }
};
PHP;
        }

        // Detect add_*_to_<table> or drop_*_from_<table> pattern
        if (preg_match('/^(?:add|drop)_.+_(?:to|from)_(.+)$/', $normalized, $m)) {
            $table = $m[1];

            return <<<PHP
<?php

declare(strict_types=1);

use Wayfinder\Database\Database;
use Wayfinder\Database\Migration;
use Wayfinder\Database\Schema;

return new class implements Migration
{
    public function up(Database \$database): void
    {
        Schema::table('{$table}', function (\\Wayfinder\\Database\\Blueprint \$table) {
            //
        });
    }

    public function down(Database \$database): void
    {
        Schema::table('{$table}', function (\\Wayfinder\\Database\\Blueprint \$table) {
            //
        });
    }
};
PHP;
        }

        // Generic fallback
        return <<<PHP
<?php

declare(strict_types=1);

use Wayfinder\Database\Database;
use Wayfinder\Database\Migration;
use Wayfinder\Database\Schema;

return new class implements Migration
{
    public function up(Database \$database): void
    {
        //
    }

    public function down(Database \$database): void
    {
        //
    }
};
PHP;
    }

    private function normalizeName(string $name): string
    {
        $normalized = strtolower(trim($name));
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized);
        $normalized = trim((string) $normalized, '_');

        if ($normalized === '') {
            throw new \InvalidArgumentException('Migration name must contain letters or numbers.');
        }

        return $normalized;
    }
}
