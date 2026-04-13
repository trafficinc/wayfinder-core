<?php

declare(strict_types=1);

namespace Wayfinder\Console;

final class MakeControllerCommand implements Command
{
    public function __construct(
        private readonly string $path,
        private readonly string $namespace,
    ) {
    }

    public function name(): string
    {
        return 'make:controller';
    }

    public function description(): string
    {
        return 'Create a new controller class.';
    }

    public function handle(array $arguments = []): int
    {
        $name = $arguments[0] ?? null;

        if (! is_string($name) || trim($name) === '') {
            fwrite(STDERR, "Usage: php wayfinder make:controller <name>\n");

            return 1;
        }

        $class = $this->normalizeClassName($name);
        $relative = str_replace('\\', '/', $class) . '.php';
        $target = rtrim($this->path, '/') . '/' . $relative;
        $directory = dirname($target);
        $namespace = trim($this->namespace, '\\') . '\\' . str_replace('/', '\\', dirname($relative));
        $namespace = rtrim(str_replace('\\.', '', $namespace), '\\');

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            fwrite(STDERR, sprintf("Unable to create controller directory [%s].\n", $directory));

            return 1;
        }

        if (file_exists($target)) {
            fwrite(STDERR, sprintf("Controller [%s] already exists.\n", $relative));

            return 1;
        }

        $className = basename($relative, '.php');

        $template = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Wayfinder\Http\Request;
use Wayfinder\Http\Response;

final class {$className}
{
    public function __invoke(Request \$request): Response
    {
        return Response::text('{$className}');
    }
}
PHP;

        if (file_put_contents($target, $template . "\n") === false) {
            fwrite(STDERR, sprintf("Unable to write controller [%s].\n", $relative));

            return 1;
        }

        fwrite(STDOUT, sprintf("Created controller: %s\n", $relative));

        return 0;
    }

    private function normalizeClassName(string $name): string
    {
        $trimmed = trim($name, '\\/');
        $parts = preg_split('/[\/\\\\]+/', $trimmed) ?: [];
        $normalized = [];

        foreach ($parts as $part) {
            $segment = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $part);
            $segment = preg_replace('/[^A-Za-z0-9]+/', ' ', (string) $segment);
            $segment = str_replace(' ', '', ucwords(strtolower(trim((string) $segment))));

            if ($segment === '') {
                continue;
            }

            $normalized[] = $segment;
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException('Controller name must contain letters or numbers.');
        }

        $last = array_pop($normalized);

        if (! str_ends_with($last, 'Controller')) {
            $last .= 'Controller';
        }

        $normalized[] = $last;

        return implode('\\', $normalized);
    }
}
