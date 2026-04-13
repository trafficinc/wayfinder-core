<?php

declare(strict_types=1);

namespace Wayfinder\Console;

final class MakeViewCommand implements Command
{
    public function __construct(
        private readonly string $path,
        private readonly string $extension = 'php',
    ) {
    }

    public function name(): string
    {
        return 'make:view';
    }

    public function description(): string
    {
        return 'Create a new view template.';
    }

    public function handle(array $arguments = []): int
    {
        $name = $arguments[0] ?? null;

        if (! is_string($name) || trim($name) === '') {
            fwrite(STDERR, "Usage: php wayfinder make:view <name>\n");

            return 1;
        }

        $relative = str_replace('.', '/', trim($name, '.')) . '.' . $this->extension;
        $target = rtrim($this->path, '/') . '/' . $relative;
        $directory = dirname($target);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            fwrite(STDERR, sprintf("Unable to create view directory [%s].\n", $directory));

            return 1;
        }

        if (file_exists($target)) {
            fwrite(STDERR, sprintf("View [%s] already exists.\n", $relative));

            return 1;
        }

        $template = <<<'PHP'
<section>
    <h1><?= htmlspecialchars($title ?? 'Wayfinder View', ENT_QUOTES, 'UTF-8') ?></h1>
</section>
PHP;

        if (file_put_contents($target, $template . "\n") === false) {
            fwrite(STDERR, sprintf("Unable to write view [%s].\n", $relative));

            return 1;
        }

        fwrite(STDOUT, sprintf("Created view: %s\n", $relative));

        return 0;
    }
}
