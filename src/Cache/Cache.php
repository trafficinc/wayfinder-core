<?php

declare(strict_types=1);

namespace Wayfinder\Cache;

interface Cache
{
    public function get(string $key, mixed $default = null): mixed;

    public function put(string $key, mixed $value, int $seconds = 3600): void;

    public function has(string $key): bool;

    public function forget(string $key): void;

    public function remember(string $key, int $seconds, callable $callback): mixed;
}
