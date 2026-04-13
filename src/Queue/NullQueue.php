<?php

declare(strict_types=1);

namespace Wayfinder\Queue;

final class NullQueue implements Queue
{
    public function push(string $job, array $payload = []): void
    {
    }

    public function pop(): ?array
    {
        return null;
    }

    public function acknowledge(array $job): void
    {
    }

    public function release(array $job): void
    {
    }

    public function fail(array $job, \Throwable $throwable): void
    {
    }

    public function recover(int $olderThanSeconds = 3600): int
    {
        return 0;
    }

    public function size(): int
    {
        return 0;
    }

    public function processingSize(): int
    {
        return 0;
    }

    public function failedSize(): int
    {
        return 0;
    }
}
