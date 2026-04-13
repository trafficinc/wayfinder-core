<?php

declare(strict_types=1);

namespace Wayfinder\Console;

final class MakeMiddlewareCommand implements Command
{
    public function __construct(
        private readonly string $path,
        private readonly string $namespace,
    ) {
    }

    public function name(): string
    {
        return 'make:middleware';
    }

    public function description(): string
    {
        return 'Create a new middleware class.';
    }

    public function handle(array $arguments = []): int
    {
        $name = $arguments[0] ?? null;

        if (! is_string($name) || trim($name) === '') {
            fwrite(STDERR, "Usage: php wayfinder make:middleware <name>\n");

            return 1;
        }

        $class = $this->normalizeClassName($name);
        $relative = str_replace('\\', '/', $class) . '.php';
        $target = rtrim($this->path, '/') . '/' . $relative;
        $directory = dirname($target);
        $namespace = trim($this->namespace, '\\') . '\\' . str_replace('/', '\\', dirname($relative));
        $namespace = rtrim(str_replace('\\.', '', $namespace), '\\');

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            fwrite(STDERR, sprintf("Unable to create middleware directory [%s].\n", $directory));

            return 1;
        }

        if (file_exists($target)) {
            fwrite(STDERR, sprintf("Middleware [%s] already exists.\n", $relative));

            return 1;
        }

        $className = basename($relative, '.php');

        $template = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Wayfinder\Contracts\Middleware;
use Wayfinder\Http\Request;
use Wayfinder\Http\Response;

final class {$className} implements Middleware
{
    public function handle(Request \$request, callable \$next): Response
    {
        return \$next(\$request);
    }
}
PHP;

        if (file_put_contents($target, $template . "\n") === false) {
            fwrite(STDERR, sprintf("Unable to write middleware [%s].\n", $relative));

            return 1;
        }

        fwrite(STDOUT, sprintf("Created middleware: %s\n", $relative));

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
            throw new \InvalidArgumentException('Middleware name must contain letters or numbers.');
        }

        return implode('\\', $normalized);
    }
}
