<?php

declare(strict_types=1);

namespace Wayfinder\Database\Concerns;

trait HasAttributes
{
    /**
     * @var array<string, mixed>
     */
    protected array $attributes = [];

    /**
     * @param array<string, mixed> $attributes
     */
    final public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    final public function fill(array $attributes): void
    {
        $this->attributes = $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    final public function toArray(): array
    {
        return $this->attributes;
    }

    final public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    final public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    final public function __get(string $key): mixed
    {
        return $this->getAttribute($key);
    }

    final public function __isset(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }
}
