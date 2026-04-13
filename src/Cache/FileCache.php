<?php

declare(strict_types=1);

namespace Wayfinder\Cache;

final class FileCache implements Cache
{
    public function __construct(
        private readonly string $path,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $payload = $this->read($key);

        if ($payload === null) {
            return $default;
        }

        return $payload['value'];
    }

    public function put(string $key, mixed $value, int $seconds = 3600): void
    {
        $directory = $this->path;

        if (! is_dir($directory) && ! @mkdir($concurrentDirectory = $directory, 0777, true) && ! is_dir($concurrentDirectory)) {
            throw new \RuntimeException(sprintf('Unable to create cache directory [%s].', $directory));
        }

        $expiresAt = $seconds > 0 ? time() + $seconds : null;
        $payload = serialize([
            'expires_at' => $expiresAt,
            'value' => $value,
        ]);

        @file_put_contents($this->pathFor($key), $payload);
    }

    public function has(string $key): bool
    {
        return $this->read($key) !== null;
    }

    public function forget(string $key): void
    {
        $path = $this->pathFor($key);

        if (is_file($path)) {
            unlink($path);
        }
    }

    public function remember(string $key, int $seconds, callable $callback): mixed
    {
        $value = $this->get($key, null);

        if ($this->has($key)) {
            return $value;
        }

        $value = $callback();
        $this->put($key, $value, $seconds);

        return $value;
    }

    /**
     * @return array{expires_at: int|null, value: mixed}|null
     */
    private function read(string $key): ?array
    {
        $path = $this->pathFor($key);

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        $payload = is_string($contents) ? @unserialize($contents) : null;

        if (! is_array($payload) || ! array_key_exists('value', $payload)) {
            $this->forget($key);

            return null;
        }

        $expiresAt = $payload['expires_at'] ?? null;

        if (is_int($expiresAt) && $expiresAt <= time()) {
            $this->forget($key);

            return null;
        }

        return $payload;
    }

    private function pathFor(string $key): string
    {
        return rtrim($this->path, '/') . '/' . sha1($key) . '.cache';
    }
}
