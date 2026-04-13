<?php

declare(strict_types=1);

namespace Wayfinder\Module;

use Wayfinder\Routing\Router;
use Wayfinder\Support\Config;
use Wayfinder\Support\Container;
use Wayfinder\View\View;

final class ModuleManager
{
    /**
     * @var list<Module>|null
     */
    private ?array $modules = null;

    /**
     * @var array<string, ServiceProvider>
     */
    private array $providers = [];

    public function __construct(
        private readonly string $modulesPath,
        private readonly ?string $cachePath = null,
        private readonly bool $cacheEnabled = false,
    ) {
    }

    /**
     * @return list<Module>
     */
    public function modules(Config $config): array
    {
        if ($this->modules !== null) {
            return $this->modules;
        }

        $this->modules = $this->applyConfiguration($this->discoverModules(), $config);

        return $this->modules;
    }

    public function mergeConfig(Config $config): void
    {
        foreach ($this->modules($config) as $module) {
            $path = $module->configPath();

            if ($path === null || ! is_dir($path)) {
                continue;
            }

            foreach (glob(rtrim($path, '/') . '/*.php') ?: [] as $file) {
                $key = basename($file, '.php');
                $values = require $file;

                if (! is_array($values)) {
                    throw new \RuntimeException(sprintf(
                        'Module config [%s] must return an array.',
                        $file,
                    ));
                }

                $config->merge([$key => $values], overwrite: false);
            }
        }
    }

    public function registerProviders(Container $container, Config $config): void
    {
        foreach ($this->modules($config) as $module) {
            $provider = $this->resolveProvider($module, $container);

            if ($provider === null) {
                continue;
            }

            $provider->register($container, $config, $module);
        }
    }

    public function bootProviders(Container $container, Router $router, Config $config): void
    {
        foreach ($this->modules($config) as $module) {
            $provider = $this->resolveProvider($module, $container);

            if ($provider === null) {
                continue;
            }

            $provider->boot($container, $router, $config, $module);
        }
    }

    public function registerViews(View $view, Config $config): void
    {
        foreach ($this->modules($config) as $module) {
            $path = $module->viewsPath();

            if ($path !== null && is_dir($path)) {
                $view->addPath($path, $module->key());
            }
        }
    }

    /**
     * @return list<string>
     */
    public function routeFiles(Config $config): array
    {
        $files = [];

        foreach ($this->modules($config) as $module) {
            $path = $module->routesPath();

            if ($path !== null && is_file($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    public function migrationPaths(Config $config): array
    {
        $paths = [];

        foreach ($this->modules($config) as $module) {
            $path = $module->migrationsPath();

            if ($path !== null && is_dir($path)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function manifest(Config $config): array
    {
        return array_map(
            static fn (Module $module): array => $module->toArray(),
            $this->modules($config),
        );
    }

    /**
     * @return list<Module>
     */
    private function discoverModules(): array
    {
        if ($this->cacheEnabled && $this->cachePath !== null && is_file($this->cachePath)) {
            /** @var list<array<string, mixed>> $manifest */
            $manifest = require $this->cachePath;

            return array_map(static fn (array $module): Module => Module::fromArray($module), $manifest);
        }

        $modules = [];

        foreach (glob(rtrim($this->modulesPath, '/') . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $modules[] = $this->discoverModule($directory);
        }

        usort($modules, static fn (Module $left, Module $right): int => strcmp($left->name(), $right->name()));
        $this->writeCache($modules);

        return $modules;
    }

    private function discoverModule(string $directory): Module
    {
        $name = basename($directory);
        $metadataFile = rtrim($directory, '/') . '/module.php';
        $metadata = is_file($metadataFile) ? require $metadataFile : [];

        if (! is_array($metadata)) {
            throw new \RuntimeException(sprintf('Module metadata [%s] must return an array.', $metadataFile));
        }

        $namespace = (string) ($metadata['namespace'] ?? ('Modules\\' . $name . '\\'));
        $providerClass = isset($metadata['provider']) && is_string($metadata['provider'])
            ? $metadata['provider']
            : $this->defaultProviderClass($namespace);

        return new Module(
            name: $name,
            path: $directory,
            namespace: $namespace,
            enabled: (bool) ($metadata['enabled'] ?? true),
            order: (int) ($metadata['order'] ?? 0),
            providerClass: $providerClass,
            routesPath: $this->resolvePath($directory, $metadata['routes'] ?? 'routes/web.php'),
            viewsPath: $this->resolveDirectory($directory, $metadata['views'] ?? 'resources/views'),
            configPath: $this->resolveDirectory($directory, $metadata['config'] ?? 'config'),
            migrationsPath: $this->resolveDirectory($directory, $metadata['migrations'] ?? 'database/migrations'),
        );
    }

    private function defaultProviderClass(string $namespace): ?string
    {
        $class = rtrim($namespace, '\\') . '\\ModuleServiceProvider';

        return class_exists($class) ? $class : null;
    }

    /**
     * @param list<Module> $modules
     * @return list<Module>
     */
    private function applyConfiguration(array $modules, Config $config): array
    {
        $enabled = $config->get('modules.enabled', []);
        $order = $config->get('modules.order', []);

        if (! is_array($enabled)) {
            $enabled = [];
        }

        if (! is_array($order)) {
            $order = [];
        }

        $orderMap = [];

        foreach (array_values(array_filter($order, 'is_string')) as $index => $name) {
            $orderMap[$name] = $index;
        }

        $modules = array_values(array_filter($modules, static function (Module $module) use ($enabled): bool {
            $override = $enabled[$module->name()] ?? $enabled[$module->key()] ?? null;

            return is_bool($override) ? $override : $module->enabled();
        }));

        usort($modules, static function (Module $left, Module $right) use ($orderMap): int {
            $leftOrder = $orderMap[$left->name()] ?? $orderMap[$left->key()] ?? PHP_INT_MAX;
            $rightOrder = $orderMap[$right->name()] ?? $orderMap[$right->key()] ?? PHP_INT_MAX;

            if ($leftOrder !== $rightOrder) {
                return $leftOrder <=> $rightOrder;
            }

            if ($left->order() !== $right->order()) {
                return $left->order() <=> $right->order();
            }

            return strcmp($left->name(), $right->name());
        });

        return $modules;
    }

    private function resolveProvider(Module $module, Container $container): ?ServiceProvider
    {
        $providerClass = $module->providerClass();

        if ($providerClass === null) {
            return null;
        }

        if (isset($this->providers[$providerClass])) {
            return $this->providers[$providerClass];
        }

        if (! class_exists($providerClass)) {
            throw new \RuntimeException(sprintf(
                'Module provider [%s] for module [%s] was not found.',
                $providerClass,
                $module->name(),
            ));
        }

        $provider = $container->has($providerClass)
            ? $container->get($providerClass)
            : new $providerClass();

        if (! $provider instanceof ServiceProvider) {
            throw new \RuntimeException(sprintf(
                'Module provider [%s] must extend %s.',
                $providerClass,
                ServiceProvider::class,
            ));
        }

        $this->providers[$providerClass] = $provider;

        return $provider;
    }

    private function resolvePath(string $basePath, mixed $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        $resolved = rtrim($basePath, '/') . '/' . ltrim($path, '/');

        return is_file($resolved) ? $resolved : null;
    }

    private function resolveDirectory(string $basePath, mixed $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        $resolved = rtrim($basePath, '/') . '/' . ltrim($path, '/');

        return is_dir($resolved) ? $resolved : null;
    }

    /**
     * @param list<Module> $modules
     */
    private function writeCache(array $modules): void
    {
        if (! $this->cacheEnabled || $this->cachePath === null) {
            return;
        }

        $directory = dirname($this->cachePath);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create module cache directory [%s].', $directory));
        }

        $payload = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export(
            array_map(static fn (Module $module): array => $module->toArray(), $modules),
            true,
        ) . ";\n";

        file_put_contents($this->cachePath, $payload);
    }
}
