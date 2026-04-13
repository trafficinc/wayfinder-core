<?php

declare(strict_types=1);

namespace Wayfinder\Routing;

final class Route
{
    /**
     * @param callable|array{0: class-string|string, 1: string} $handler
     * @param list<callable|string> $middleware
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly mixed $handler,
        private readonly ?string $name = null,
        private readonly array $middleware = [],
        private readonly string $pattern = '',
    ) {
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function handler(): mixed
    {
        return $this->handler;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    /**
     * @return list<callable|string>
     */
    public function middleware(): array
    {
        return $this->middleware;
    }

    public function pattern(): string
    {
        return $this->pattern;
    }

    /**
     * @return array<string, string>|null
     */
    public function matches(string $method, string $path): ?array
    {
        if ($this->method !== $method) {
            return null;
        }

        if (preg_match($this->pattern, $path, $matches) !== 1) {
            return null;
        }

        return array_filter(
            $matches,
            static fn (string|int $key): bool => is_string($key),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
