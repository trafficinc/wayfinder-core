<?php

declare(strict_types=1);

namespace Wayfinder\Console;

use Wayfinder\Console\Concerns\GeneratesCode;

abstract class AbstractMakeDataClassCommand implements Command
{
    use GeneratesCode;

    public function __construct(
        private readonly string $appPath,
        private readonly string $appNamespace,
        private readonly string $modulePath,
        private readonly string $moduleNamespace,
    ) {
    }

    public function handle(array $arguments = []): int
    {
        $input = $this->parseGeneratorInput($arguments);

        if ($input === null) {
            fwrite(STDERR, sprintf("Usage: php wayfinder %s <name> [--module=ModuleName] [--namespace=Sub\\\\Namespace]\n", $this->name()));

            return 1;
        }

        $class = $this->normalizeClassName($input['name'], $this->classSuffix());
        $relative = str_replace('\\', '/', $class) . '.php';
        $customNamespace = $input['namespace'] !== null ? $this->normalizeNamespace($input['namespace']) : '';

        if ($input['module'] !== null) {
            $module = $this->normalizeNamespace($input['module']);

            if ($module === '') {
                fwrite(STDERR, "Module name must contain letters or numbers.\n");

                return 1;
            }

            $basePath = rtrim($this->modulePath, '/') . '/' . str_replace('\\', '/', $module) . '/' . $this->directoryName();
            $baseNamespace = trim($this->moduleNamespace, '\\') . '\\' . $module . '\\' . $this->directoryName();
        } else {
            $basePath = rtrim($this->appPath, '/') . '/' . $this->directoryName();
            $baseNamespace = trim($this->appNamespace, '\\') . '\\' . $this->directoryName();
        }

        if ($customNamespace !== '') {
            $basePath .= '/' . str_replace('\\', '/', $customNamespace);
            $baseNamespace .= '\\' . $customNamespace;
        }

        $target = $basePath . '/' . $relative;
        $directory = dirname($target);

        if (! $this->ensureDirectoryExists($directory, strtolower($this->resourceLabel()))) {
            return 1;
        }

        if (file_exists($target)) {
            fwrite(STDERR, sprintf("%s [%s] already exists.\n", $this->resourceLabel(), str_replace(rtrim(dirname($basePath), '/') . '/', '', $target)));

            return 1;
        }

        $className = basename($relative, '.php');
        $namespace = trim($baseNamespace . '\\' . str_replace('/', '\\', dirname($relative)), '\\');
        $namespace = rtrim(str_replace('\\.', '', $namespace), '\\');

        if (file_put_contents($target, $this->template($namespace, $className) . "\n") === false) {
            fwrite(STDERR, sprintf("Unable to write %s [%s].\n", strtolower($this->resourceLabel()), $target));

            return 1;
        }

        fwrite(STDOUT, sprintf("Created %s: %s\n", strtolower($this->resourceLabel()), $target));

        return 0;
    }

    abstract protected function directoryName(): string;

    abstract protected function classSuffix(): string;

    abstract protected function resourceLabel(): string;

    abstract protected function template(string $namespace, string $className): string;
}
