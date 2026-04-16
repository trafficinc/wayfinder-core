<?php

declare(strict_types=1);

namespace Wayfinder\Pagination;

final class Paginator
{
    /**
     * @param list<mixed> $items
     */
    public function __construct(
        private readonly array $items,
        private readonly int $total,
        private readonly int $perPage,
        private readonly int $currentPage,
    ) {
        if ($perPage < 1) {
            throw new \InvalidArgumentException('Per-page value must be 1 or greater.');
        }

        if ($currentPage < 1) {
            throw new \InvalidArgumentException('Current page must be 1 or greater.');
        }
    }

    /**
     * @return list<mixed>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function currentPage(): int
    {
        return $this->currentPage;
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function hasPages(): bool
    {
        return $this->lastPage() > 1;
    }

    public function hasNextPage(): bool
    {
        return $this->currentPage < $this->lastPage();
    }

    public function hasPreviousPage(): bool
    {
        return $this->currentPage > 1;
    }

    public function nextPage(): ?int
    {
        return $this->hasNextPage() ? $this->currentPage + 1 : null;
    }

    public function previousPage(): ?int
    {
        return $this->hasPreviousPage() ? $this->currentPage - 1 : null;
    }

    public function from(): ?int
    {
        if ($this->items === []) {
            return null;
        }

        return (($this->currentPage - 1) * $this->perPage) + 1;
    }

    public function to(): ?int
    {
        if ($this->items === []) {
            return null;
        }

        return min($this->currentPage * $this->perPage, $this->total);
    }

    /**
     * @param callable(mixed): mixed $callback
     */
    public function map(callable $callback): self
    {
        return new self(
            array_values(array_map($callback, $this->items)),
            $this->total,
            $this->perPage,
            $this->currentPage,
        );
    }
}
