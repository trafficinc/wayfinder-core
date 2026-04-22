<?php

declare(strict_types=1);

namespace Wayfinder\Database;

final class DatabaseManager
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $connections;

    /**
     * @var array<string, Database>
     */
    private array $instances = [];

    public function __construct(
        array $config = [],
    ) {
        ['default' => $this->defaultConnection, 'connections' => $this->connections] = self::normalizeConfig($config);
    }

    private string $defaultConnection;

    public function hasConnections(): bool
    {
        return $this->connections !== [];
    }

    public function defaultConnectionName(): string
    {
        return $this->defaultConnection;
    }

    public function connection(?string $name = null): Database
    {
        $name ??= $this->defaultConnection;

        if (! array_key_exists($name, $this->connections)) {
            throw new \RuntimeException(sprintf('Database connection [%s] is not configured.', $name));
        }

        return $this->instances[$name] ??= new Database($this->connections[$name]);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{default: string, connections: array<string, array<string, mixed>>}
     */
    public static function normalizeConfig(array $config): array
    {
        $default = $config['default'] ?? 'default';
        $connections = $config['connections'] ?? null;

        if (is_array($default) && $connections === null) {
            return [
                'default' => 'default',
                'connections' => [
                    'default' => $default,
                ],
            ];
        }

        if (! is_array($connections)) {
            return [
                'default' => is_string($default) && $default !== '' ? $default : 'default',
                'connections' => [],
            ];
        }

        $normalizedConnections = [];

        foreach ($connections as $name => $connection) {
            if (! is_string($name) || $name === '' || ! is_array($connection)) {
                continue;
            }

            $normalizedConnections[$name] = $connection;
        }

        return [
            'default' => is_string($default) && $default !== '' ? $default : 'default',
            'connections' => $normalizedConnections,
        ];
    }
}
