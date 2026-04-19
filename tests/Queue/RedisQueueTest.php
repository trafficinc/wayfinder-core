<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Queue;

use PHPUnit\Framework\TestCase;
use Wayfinder\Queue\RedisQueue;
use Wayfinder\Queue\RedisQueueStore;

final class RedisQueueTest extends TestCase
{
    private RedisQueue $queue;

    protected function setUp(): void
    {
        $this->queue = new RedisQueue(new InMemoryRedisQueueStore());
    }

    public function testPushStoresPendingJob(): void
    {
        $this->queue->push('ExampleJob', ['alpha' => 'beta']);

        self::assertSame(1, $this->queue->size());
        self::assertSame(0, $this->queue->processingSize());
    }

    public function testPopReturnsNextJobAndMovesItToProcessing(): void
    {
        $this->queue->push('ExampleJob', ['alpha' => 'beta']);

        $job = $this->queue->pop();

        self::assertSame('ExampleJob', $job['job'] ?? null);
        self::assertSame('beta', $job['payload']['alpha'] ?? null);
        self::assertSame(0, $this->queue->size());
        self::assertSame(1, $this->queue->processingSize());
    }

    public function testAcknowledgeRemovesProcessingJob(): void
    {
        $this->queue->push('ExampleJob');
        $job = $this->queue->pop();

        self::assertIsArray($job);

        $this->queue->acknowledge($job);

        self::assertSame(0, $this->queue->processingSize());
    }

    public function testReleaseReturnsJobToPendingQueue(): void
    {
        $this->queue->push('ExampleJob');
        $job = $this->queue->pop();

        self::assertIsArray($job);

        $this->queue->release($job);

        self::assertSame(1, $this->queue->size());
        self::assertSame(0, $this->queue->processingSize());
    }

    public function testFailMovesJobToFailedState(): void
    {
        $this->queue->push('ExampleJob');
        $job = $this->queue->pop();

        self::assertIsArray($job);

        $this->queue->fail($job, new \RuntimeException('boom'));

        self::assertSame(1, $this->queue->failedSize());
        self::assertSame(0, $this->queue->processingSize());
    }

    public function testRecoverMovesStaleJobsBackToPending(): void
    {
        $this->queue->push('ExampleJob');
        $job = $this->queue->pop();

        self::assertIsArray($job);

        sleep(1);

        self::assertSame(1, $this->queue->recover(0));
        self::assertSame(1, $this->queue->size());
        self::assertSame(0, $this->queue->processingSize());
    }
}

final class InMemoryRedisQueueStore implements RedisQueueStore
{
    /**
     * @var array<string, array{job: string, payload: array<string, mixed>, attempts: int, processing_started_at: int|null, failed: bool}>
     */
    private array $jobs = [];

    /**
     * @var list<string>
     */
    private array $pending = [];

    /**
     * @var array<string, int>
     */
    private array $processing = [];

    /**
     * @var array<string, true>
     */
    private array $failed = [];

    private int $nextId = 0;

    public function push(string $job, array $payload = []): void
    {
        $id = (string) (++$this->nextId);
        $this->jobs[$id] = [
            'job' => $job,
            'payload' => $payload,
            'attempts' => 0,
            'processing_started_at' => null,
            'failed' => false,
        ];
        $this->pending[] = $id;
    }

    public function pop(): ?array
    {
        $id = array_shift($this->pending);

        if (! is_string($id) || ! isset($this->jobs[$id])) {
            return null;
        }

        $this->jobs[$id]['attempts']++;
        $this->jobs[$id]['processing_started_at'] = time();
        $this->processing[$id] = $this->jobs[$id]['processing_started_at'];

        return [
            'job' => $this->jobs[$id]['job'],
            'payload' => $this->jobs[$id]['payload'],
            '__id' => $id,
            '__attempts' => $this->jobs[$id]['attempts'],
        ];
    }

    public function acknowledge(array $job): void
    {
        $id = $this->jobId($job);

        if ($id === null) {
            return;
        }

        unset($this->jobs[$id], $this->processing[$id], $this->failed[$id]);
    }

    public function release(array $job): void
    {
        $id = $this->jobId($job);

        if ($id === null || ! isset($this->jobs[$id])) {
            return;
        }

        unset($this->processing[$id]);
        $this->jobs[$id]['processing_started_at'] = null;
        $this->pending[] = $id;
    }

    public function fail(array $job, \Throwable $throwable): void
    {
        $id = $this->jobId($job);

        if ($id === null || ! isset($this->jobs[$id])) {
            return;
        }

        unset($this->processing[$id]);
        $this->jobs[$id]['failed'] = true;
        $this->failed[$id] = true;
    }

    public function recover(int $olderThanSeconds = 3600): int
    {
        $threshold = time() - $olderThanSeconds;
        $recovered = 0;

        foreach ($this->processing as $id => $startedAt) {
            if ($startedAt > $threshold || ! isset($this->jobs[$id])) {
                continue;
            }

            unset($this->processing[$id]);
            $this->jobs[$id]['processing_started_at'] = null;
            $this->pending[] = $id;
            $recovered++;
        }

        return $recovered;
    }

    public function size(): int
    {
        return count($this->pending);
    }

    public function processingSize(): int
    {
        return count($this->processing);
    }

    public function failedSize(): int
    {
        return count($this->failed);
    }

    private function jobId(array $job): ?string
    {
        $id = $job['__id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }
}
