<?php

declare(strict_types=1);

namespace Wayfinder\Queue;

interface Queue
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
     * Release a job back to the pending queue for another attempt.
     * The attempt counter recorded in the job is preserved so that
     * Worker can enforce a maxAttempts ceiling.
     *
     * @param array<string, mixed> $job
     */
    public function release(array $job): void;

    /**
     * @param array<string, mixed> $job
     */
    public function fail(array $job, \Throwable $throwable): void;

    /**
     * Move processing jobs that have been in-flight longer than
     * $olderThanSeconds back to the pending queue so they can be
     * retried after a worker crash.
     *
     * @return int Number of jobs recovered.
     */
    public function recover(int $olderThanSeconds = 3600): int;

    public function size(): int;

    public function processingSize(): int;

    public function failedSize(): int;
}
