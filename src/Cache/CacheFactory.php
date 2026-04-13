<?php

declare(strict_types=1);

namespace Wayfinder\Cache;

final class CacheFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public function make(array $config): Cache
    {
        return match ($config['driver'] ?? 'null') {
            'file' => new FileCache((string) ($config['path'] ?? sys_get_temp_dir() . '/wayfinder-cache')),
            default => new NullCache(),
        };
    }
}
