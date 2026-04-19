<?php

declare(strict_types=1);

namespace Wayfinder\Queue;

interface RedisQueueStore
{
    /**
     * @param array<string, mixed> $payload
     */
    public function push(string $job, array $payload = []): void;

    /**
     * @return array<string, mixed>|null
     */
    public function pop(): ?array;

    /**
     * @param array<string, mixed> $job
     */
    public function acknowledge(array $job): void;

    /**
     * @param array<string, mixed> $job
     */
    public function release(array $job): void;

    /**
     * @param array<string, mixed> $job
     */
    public function fail(array $job, \Throwable $throwable): void;

    public function recover(int $olderThanSeconds = 3600): int;

    public function size(): int;

    public function processingSize(): int;

    public function failedSize(): int;
}
