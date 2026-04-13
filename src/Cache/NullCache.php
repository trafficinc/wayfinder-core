<?php

declare(strict_types=1);

namespace Wayfinder\Cache;

final class NullCache implements Cache
{
    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function put(string $key, mixed $value, int $seconds = 3600): void
    {
    }

    public function has(string $key): bool
    {
        return false;
    }

    public function forget(string $key): void
    {
    }

    public function remember(string $key, int $seconds, callable $callback): mixed
    {
        return $callback();
    }
}
