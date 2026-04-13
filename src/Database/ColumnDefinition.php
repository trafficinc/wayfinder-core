<?php

declare(strict_types=1);

namespace Wayfinder\Database;

final class ColumnDefinition
{
    public bool $nullable = false;
    public bool $hasDefault = false;
    public mixed $defaultValue = null;
    public bool $unsigned = false;
    public bool $autoIncrement = false;
    public bool $primaryKey = false;
    public bool $uniqueKey = false;
    public ?string $after = null;
    public bool $change = false;

    /**
     * @param list<string> $allowed
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly ?int $length = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
        public readonly array $allowed = [],
    ) {
    }

    public function nullable(bool $value = true): static
    {
        $this->nullable = $value;

        return $this;
    }

    public function default(mixed $value): static
    {
        $this->hasDefault = true;
        $this->defaultValue = $value;

        return $this;
    }

    public function unsigned(): static
    {
        $this->unsigned = true;

        return $this;
    }

    public function unique(): static
    {
        $this->uniqueKey = true;

        return $this;
    }

    public function after(string $column): static
    {
        $this->after = $column;

        return $this;
    }

    public function change(): static
    {
        $this->change = true;

        return $this;
    }
}
