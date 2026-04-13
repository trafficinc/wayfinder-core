<?php

declare(strict_types=1);

namespace Wayfinder\Console;

final class ModuleInstallCommand implements Command
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
        return 'module:install';
    }

    public function description(): string
    {
        return 'Install a packaged module or link a local custom module.';
    }

    public function handle(array $arguments = []): int
    {
        $target = $arguments[0] ?? null;

        if (! is_string($target) || trim($target) === '') {
            fwrite($this->stderr ?? STDERR, "Usage: php wayfinder module:install <alias|vendor/package|/path/to/module> [--module=Name] [--repository=URL|PATH]\n");

            return 1;
        }

        ['options' => $options] = $this->parseArguments(array_slice($arguments, 1));
        $moduleName = isset($options['module']) && is_string($options['module']) ? $this->normalizeModuleName($options['module']) : null;
        $repository = isset($options['repository']) && is_string($options['repository']) ? trim($options['repository']) : null;

        if ($repository === '') {
            $repository = null;
        }

        if (is_dir($target)) {
            $sourcePath = realpath($target);

            if ($sourcePath === false) {
                fwrite($this->stderr ?? STDERR, sprintf("Unable to resolve module path [%s].\n", $target));

                return 1;
            }

            $moduleName ??= $this->normalizeModuleName(basename($sourcePath));

            return $this->linkModule($sourcePath, $moduleName);
        }

        $resolved = $this->resolvePackageTarget($target, $moduleName, $repository);
        $moduleName = $resolved['module'];
        $package = $resolved['package'];
        $repository = $resolved['repository'];

        if ($repository !== null && $this->runComposerRepositoryConfig($package, $repository) !== 0) {
            return 1;
        }

        if ($this->runComposer(['composer', 'require', $package]) !== 0) {
            return 1;
        }

        $vendorPath = $this->appPath . '/vendor/' . $package;

        if (! is_file($vendorPath . '/module.php')) {
            fwrite($this->stderr ?? STDERR, sprintf("Installed package [%s] does not look like a Wayfinder module.\n", $package));

            return 1;
        }

        return $this->linkModule($vendorPath, $moduleName);
    }

    /**
     * @param list<string> $arguments
     * @return array{options: array<string, string>}
     */
    private function parseArguments(array $arguments): array
    {
        $options = [];

        foreach ($arguments as $argument) {
            if (! str_starts_with($argument, '--')) {
                continue;
            }

            $parts = explode('=', substr($argument, 2), 2);
            $key = $parts[0] ?? '';
            $value = $parts[1] ?? 'true';

            if ($key !== '') {
                $options[$key] = $value;
            }
        }

        return ['options' => $options];
    }

    /**
     * @return array{package: string, module: string, repository: string|null}
     */
    private function resolvePackageTarget(string $target, ?string $moduleName, ?string $repository): array
    {
        $alias = $this->aliases[$target] ?? null;

        if (is_array($alias)) {
            $package = isset($alias['package']) && is_string($alias['package']) ? $alias['package'] : null;
            $module = isset($alias['module']) && is_string($alias['module']) ? $this->normalizeModuleName($alias['module']) : null;
            $repo = isset($alias['repository']) && is_string($alias['repository']) ? $alias['repository'] : null;

            if ($package === null) {
                throw new \RuntimeException(sprintf('Module alias [%s] is missing a package name.', $target));
            }

            return [
                'package' => $package,
                'module' => $moduleName ?? $module ?? $this->deriveModuleNameFromPackage($package),
                'repository' => $repository ?? $repo,
            ];
        }

        if (! str_contains($target, '/')) {
            throw new \RuntimeException(sprintf(
                'Unknown module alias [%s]. Use a configured alias, a vendor/package name, or a local module path.',
                $target,
            ));
        }

        return [
            'package' => $target,
            'module' => $moduleName ?? $this->deriveModuleNameFromPackage($target),
            'repository' => $repository,
        ];
    }

    private function runComposerRepositoryConfig(string $package, string $repository): int
    {
        $repositoryKey = str_replace('/', '-', $package);

        if (is_dir($repository)) {
            $json = json_encode([
                'type' => 'path',
                'url' => $repository,
                'options' => ['symlink' => true],
            ], JSON_UNESCAPED_SLASHES);

            if ($json === false) {
                fwrite($this->stderr ?? STDERR, sprintf("Unable to encode repository config for [%s].\n", $package));

                return 1;
            }

            return $this->runComposer([
                'composer',
                'config',
                '--json',
                sprintf('repositories.%s', $repositoryKey),
                $json,
            ]);
        }

        return $this->runComposer([
            'composer',
            'config',
            sprintf('repositories.%s', $repositoryKey),
            'vcs',
            $repository,
        ]);
    }

    private function linkModule(string $sourcePath, string $moduleName): int
    {
        if (! is_dir($this->modulesPath) && ! mkdir($this->modulesPath, 0777, true) && ! is_dir($this->modulesPath)) {
            fwrite($this->stderr ?? STDERR, sprintf("Unable to create modules directory [%s].\n", $this->modulesPath));

            return 1;
        }

        $linkPath = rtrim($this->modulesPath, '/') . '/' . $moduleName;

        if (file_exists($linkPath) || is_link($linkPath)) {
            fwrite($this->stderr ?? STDERR, sprintf("Module link path [%s] already exists.\n", $linkPath));

            return 1;
        }

        $target = $this->relativePath(dirname($linkPath), $sourcePath);

        if (! symlink($target, $linkPath)) {
            fwrite($this->stderr ?? STDERR, sprintf("Unable to create module symlink [%s].\n", $linkPath));

            return 1;
        }

        fwrite($this->stdout ?? STDOUT, sprintf("Module [%s] linked at [%s].\n", $moduleName, $linkPath));

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

    private function relativePath(string $fromDirectory, string $toPath): string
    {
        $from = explode('/', trim(realpath($fromDirectory) ?: $fromDirectory, '/'));
        $to = explode('/', trim(realpath($toPath) ?: $toPath, '/'));

        while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        $up = array_fill(0, count($from), '..');
        $relative = array_merge($up, $to);

        return $relative === [] ? '.' : implode('/', $relative);
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
