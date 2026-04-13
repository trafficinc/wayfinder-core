<?php

declare(strict_types=1);

namespace Wayfinder\Console;

final class ModuleUninstallCommand implements Command
{
    /**
     * @param array<string, array{package?: string, module?: string, repository?: string}> $aliases
     * @param callable(array<int, string>, string): int|null $runner
     */
    public function __construct(
        private readonly string $appPath,
        private readonly string $modulesPath,
        private readonly array $aliases = [],
        private readonly mixed $runner = null,
        private readonly mixed $stdout = null,
        private readonly mixed $stderr = null,
    ) {
    }

    public function name(): string
    {
        return 'module:uninstall';
    }

    public function description(): string
    {
        return 'Uninstall a packaged module or unlink a local custom module.';
    }

    public function handle(array $arguments = []): int
    {
        $target = $arguments[0] ?? null;

        if (! is_string($target) || trim($target) === '') {
            fwrite($this->stderr ?? STDERR, "Usage: php wayfinder module:uninstall <alias|vendor/package|ModuleName>\n");

            return 1;
        }

        $moduleName = null;
        $package = null;
        $unsetRepositoryKey = null;

        $alias = $this->aliases[$target] ?? null;

        if (is_array($alias)) {
            $package = isset($alias['package']) && is_string($alias['package']) ? $alias['package'] : null;
            $moduleName = isset($alias['module']) && is_string($alias['module']) ? $this->normalizeModuleName($alias['module']) : null;
            $unsetRepositoryKey = $package !== null ? str_replace('/', '-', $package) : null;
        } elseif (str_contains($target, '/')) {
            $package = $target;
            $moduleName = $this->deriveModuleNameFromPackage($target);
        } else {
            $moduleName = $this->normalizeModuleName($target);
        }

        $linkPath = rtrim($this->modulesPath, '/') . '/' . $moduleName;

        if (is_link($linkPath)) {
            if (! unlink($linkPath)) {
                fwrite($this->stderr ?? STDERR, sprintf("Unable to remove module symlink [%s].\n", $linkPath));

                return 1;
            }

            fwrite($this->stdout ?? STDOUT, sprintf("Removed module symlink [%s].\n", $linkPath));
        } elseif (file_exists($linkPath)) {
            fwrite($this->stderr ?? STDERR, sprintf("Module path [%s] exists but is not a symlink. Refusing to remove it.\n", $linkPath));

            return 1;
        }

        if ($package === null) {
            return 0;
        }

        if ($this->runComposer(['composer', 'remove', $package]) !== 0) {
            return 1;
        }

        if ($unsetRepositoryKey !== null) {
            $this->runComposer(['composer', 'config', '--unset', sprintf('repositories.%s', $unsetRepositoryKey)]);
        }

        return 0;
    }

    private function deriveModuleNameFromPackage(string $package): string
    {
        $parts = explode('/', $package);

        return $this->normalizeModuleName((string) end($parts));
    }

    private function normalizeModuleName(string $name): string
    {
        $normalized = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', trim($name));
        $normalized = preg_replace('/[^A-Za-z0-9]+/', ' ', (string) $normalized);
        $normalized = str_replace(' ', '', ucwords(strtolower(trim((string) $normalized))));

        if ($normalized === '') {
            throw new \InvalidArgumentException('Module name must contain letters or numbers.');
        }

        return $normalized;
    }

    /**
     * @param list<string> $command
     */
    private function runComposer(array $command): int
    {
        if (is_callable($this->runner)) {
            return (int) ($this->runner)($command, $this->appPath);
        }

        $process = proc_open(
            $command,
            [
                0 => STDIN,
                1 => $this->stdout ?? STDOUT,
                2 => $this->stderr ?? STDERR,
            ],
            $pipes,
            $this->appPath,
        );

        if (! is_resource($process)) {
            fwrite($this->stderr ?? STDERR, sprintf("Unable to run command [%s].\n", implode(' ', $command)));

            return 1;
        }

        return proc_close($process);
    }
}
