<?php

declare(strict_types=1);

namespace Wayfinder\Queue;

final class RedisQueue implements Queue
{
    public function __construct(
        private readonly RedisQueueStore $store,
    ) {
    }

    public function push(string $job, array $payload = []): void
    {
        $this->store->push($job, $payload);
    }

    public function pop(): ?array
    {
        return $this->store->pop();
    }

    public function acknowledge(array $job): void
    {
        $this->store->acknowledge($job);
    }

    public function release(array $job): void
    {
        $this->store->release($job);
    }

    public function fail(array $job, \Throwable $throwable): void
    {
        $this->store->fail($job, $throwable);
    }

    public function recover(int $olderThanSeconds = 3600): int
    {
        return $this->store->recover($olderThanSeconds);
    }

    public function size(): int
    {
        return $this->store->size();
    }

    public function processingSize(): int
    {
        return $this->store->processingSize();
    }

    public function failedSize(): int
    {
        return $this->store->failedSize();
    }
}
