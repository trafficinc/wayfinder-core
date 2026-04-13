<?php

declare(strict_types=1);

namespace Wayfinder\Module;

final class Module
{
    public function __construct(
        private readonly string $name,
        private readonly string $path,
        private readonly string $namespace,
        private readonly bool $enabled = true,
        private readonly int $order = 0,
        private readonly ?string $providerClass = null,
        private readonly ?string $routesPath = null,
        private readonly ?string $viewsPath = null,
        private readonly ?string $configPath = null,
        private readonly ?string $migrationsPath = null,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function key(): string
    {
        return strtolower($this->name);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function order(): int
    {
        return $this->order;
    }

    public function providerClass(): ?string
    {
        return $this->providerClass;
    }

    public function routesPath(): ?string
    {
        return $this->routesPath;
    }

    public function viewsPath(): ?string
    {
        return $this->viewsPath;
    }

    public function configPath(): ?string
    {
        return $this->configPath;
    }

    public function migrationsPath(): ?string
    {
        return $this->migrationsPath;
    }

    /**
     * @return array{
     *     name: string,
     *     path: string,
     *     namespace: string,
     *     enabled: bool,
     *     order: int,
     *     provider: string|null,
     *     routes_path: string|null,
     *     views_path: string|null,
     *     config_path: string|null,
     *     migrations_path: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'path' => $this->path,
            'namespace' => $this->namespace,
            'enabled' => $this->enabled,
            'order' => $this->order,
            'provider' => $this->providerClass,
            'routes_path' => $this->routesPath,
            'views_path' => $this->viewsPath,
            'config_path' => $this->configPath,
            'migrations_path' => $this->migrationsPath,
        ];
    }

    /**
     * @param array{
     *     name: string,
     *     path: string,
     *     namespace: string,
     *     enabled?: bool,
     *     order?: int,
     *     provider?: string|null,
     *     routes_path?: string|null,
     *     views_path?: string|null,
     *     config_path?: string|null,
     *     migrations_path?: string|null
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            $data['path'],
            $data['namespace'],
            (bool) ($data['enabled'] ?? true),
            (int) ($data['order'] ?? 0),
            isset($data['provider']) && is_string($data['provider']) ? $data['provider'] : null,
            isset($data['routes_path']) && is_string($data['routes_path']) ? $data['routes_path'] : null,
            isset($data['views_path']) && is_string($data['views_path']) ? $data['views_path'] : null,
            isset($data['config_path']) && is_string($data['config_path']) ? $data['config_path'] : null,
            isset($data['migrations_path']) && is_string($data['migrations_path']) ? $data['migrations_path'] : null,
        );
    }
}
