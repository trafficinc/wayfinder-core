<?php

declare(strict_types=1);

namespace Wayfinder\Support;

final class Config
{
    /**
     * @param array<string, mixed> $items
     */
    public function __construct(
        private array $items = [],
    ) {
    }

    /**
     * @param array<string, mixed> $items
     */
    public static function fromDirectory(string $directory): self
    {
        $items = [];

        foreach (glob(rtrim($directory, '/') . '/*.php') ?: [] as $file) {
            $key = basename($file, '.php');
            $items[$key] = require $file;
        }

        return new self($items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function set(string $key, array $values): void
    {
        $this->items[$key] = $values;
    }

    /**
     * @param array<string, mixed> $items
     */
    public function merge(array $items, bool $overwrite = true): void
    {
        $this->items = self::mergeItems($this->items, $items, $overwrite);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private static function mergeItems(array $base, array $incoming, bool $overwrite): array
    {
        foreach ($incoming as $key => $value) {
            if (array_key_exists($key, $base) && is_array($base[$key]) && is_array($value)) {
                $base[$key] = self::mergeItems($base[$key], $value, $overwrite);
                continue;
            }

            if (! array_key_exists($key, $base) || $overwrite) {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
