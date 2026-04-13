<?php

declare(strict_types=1);

namespace Wayfinder\Support;

use ReflectionClass;
use ReflectionNamedType;
use Wayfinder\Contracts\Container as ContainerContract;

final class Container implements ContainerContract
{
    /**
     * @var array<string, mixed>
     */
    private array $instances = [];

    /**
     * @var array<string, array{factory: callable(self): mixed, shared: bool}>
     */
    private array $bindings = [];

    public function bind(string $id, callable|string|null $concrete = null): self
    {
        $this->bindings[$id] = [
            'factory' => $this->normalizeFactory($id, $concrete),
            'shared' => false,
        ];

        return $this;
    }

    public function singleton(string $id, callable|string|null $concrete = null): self
    {
        $this->bindings[$id] = [
            'factory' => $this->normalizeFactory($id, $concrete),
            'shared' => true,
        ];

        return $this;
    }

    public function instance(string $id, mixed $instance): self
    {
        $this->instances[$id] = $instance;

        return $this;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances)
            || array_key_exists($id, $this->bindings)
            || class_exists($id);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (array_key_exists($id, $this->bindings)) {
            $binding = $this->bindings[$id];
            $resolved = ($binding['factory'])($this);

            if ($binding['shared']) {
                $this->instances[$id] = $resolved;
            }

            return $resolved;
        }

        if (class_exists($id)) {
            return $this->build($id);
        }

        throw new \RuntimeException(sprintf('Nothing is bound for [%s].', $id));
    }

    public function build(string $class): object
    {
        $reflection = new ReflectionClass($class);

        if (! $reflection->isInstantiable()) {
            throw new \RuntimeException(sprintf('Class [%s] is not instantiable.', $class));
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return new $class();
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                $arguments[] = $this->get($type->getName());
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            throw new \RuntimeException(sprintf(
                'Unable to resolve constructor parameter [$%s] for [%s].',
                $parameter->getName(),
                $class,
            ));
        }

        return $reflection->newInstanceArgs($arguments);
    }

    private function normalizeFactory(string $id, callable|string|null $concrete): callable
    {
        if (is_callable($concrete)) {
            return $concrete;
        }

        $class = is_string($concrete) ? $concrete : $id;

        return fn (self $container): object => $container->build($class);
    }
}
