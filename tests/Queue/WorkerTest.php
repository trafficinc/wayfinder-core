<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Queue;

use PHPUnit\Framework\TestCase;
use Wayfinder\Logging\NullLogger;
use Wayfinder\Queue\FileQueue;
use Wayfinder\Queue\Job;
use Wayfinder\Queue\Worker;
use Wayfinder\Support\Container;
use Wayfinder\Tests\Concerns\UsesTempDirectory;

final class WorkerTest extends TestCase
{
    use UsesTempDirectory;

    private FileQueue $queue;
    private Container $container;
    private Worker $worker;

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
        $this->queue = new FileQueue($this->tempDir);
        $this->container = new Container();
        $this->worker = new Worker($this->queue, $this->container, new NullLogger());
    }

    protected function tearDown(): void
    {
        WorkerCountingJob::$handled = 0;
        $this->tearDownTempDirectory();
    }

    public function testRunNextReturnsEmptyWhenQueueIsEmpty(): void
    {
        $result = $this->worker->runNext();

        self::assertSame('empty', $result['status']);
    }

    public function testRunNextProcessesJobSuccessfully(): void
    {
        $this->container->instance(WorkerCountingJob::class, new WorkerCountingJob());
        $this->queue->push(WorkerCountingJob::class, ['value' => 42]);

        $result = $this->worker->runNext();

        self::assertSame('processed', $result['status']);
        self::assertSame(WorkerCountingJob::class, $result['job']);
        self::assertSame(1, WorkerCountingJob::$handled);
    }

    public function testRunNextPassesPayloadToJob(): void
    {
        $this->container->instance(WorkerPayloadJob::class, new WorkerPayloadJob());
        $this->queue->push(WorkerPayloadJob::class, ['key' => 'expected-value']);

        $this->worker->runNext();

        self::assertSame('expected-value', WorkerPayloadJob::$lastPayload['key'] ?? null);
    }

    public function testRunNextAcknowledgesJobAfterSuccess(): void
    {
        $this->container->instance(WorkerCountingJob::class, new WorkerCountingJob());
        $this->queue->push(WorkerCountingJob::class);

        $this->worker->runNext();

        self::assertCount(0, glob($this->tempDir . '/processing/*.job') ?: []);
    }

    public function testRunNextHandlesJobException(): void
    {
        // maxAttempts:1 = no retries, first failure is permanent
        $worker = new Worker($this->queue, $this->container, new NullLogger(), maxAttempts: 1);
        $this->container->instance(WorkerFailingJob::class, new WorkerFailingJob());
        $this->queue->push(WorkerFailingJob::class);

        $result = $worker->runNext();

        self::assertSame('failed', $result['status']);
        self::assertSame(WorkerFailingJob::class, $result['job']);
        self::assertStringContainsString('Job failed deliberately', $result['error'] ?? '');
    }

    public function testRunNextMovesFailingJobToFailedQueue(): void
    {
        // maxAttempts:1 = no retries, first failure is permanent
        $worker = new Worker($this->queue, $this->container, new NullLogger(), maxAttempts: 1);
        $this->container->instance(WorkerFailingJob::class, new WorkerFailingJob());
        $this->queue->push(WorkerFailingJob::class);

        $worker->runNext();

        self::assertSame(0, $this->queue->size());
        self::assertSame(1, $this->queue->failedSize());
        self::assertCount(0, glob($this->tempDir . '/processing/*.job') ?: []);
    }

    public function testRunNextHandlesClassNotImplementingJobInterface(): void
    {
        // maxAttempts:1 = no retries, so invalid class immediately goes to failed
        $worker = new Worker($this->queue, $this->container, new NullLogger(), maxAttempts: 1);
        $this->container->instance(WorkerNotAJob::class, new WorkerNotAJob());
        $this->queue->push(WorkerNotAJob::class);

        $result = $worker->runNext();

        self::assertSame('failed', $result['status']);
        self::assertSame(1, $this->queue->failedSize());
    }

    public function testPoisonJobAlwaysEndsInFailed(): void
    {
        // maxAttempts:1 = no retries; each job fails permanently on first attempt
        $worker = new Worker($this->queue, $this->container, new NullLogger(), maxAttempts: 1);
        $this->container->instance(WorkerFailingJob::class, new WorkerFailingJob());

        $this->queue->push(WorkerFailingJob::class);
        $this->queue->push(WorkerFailingJob::class);

        $worker->runNext();
        $worker->runNext();

        self::assertSame(0, $this->queue->size());
        self::assertSame(2, $this->queue->failedSize());
    }

    public function testWorkerProcessesJobsUntilQueueEmpty(): void
    {
        $this->container->instance(WorkerCountingJob::class, new WorkerCountingJob());

        $this->queue->push(WorkerCountingJob::class);
        $this->queue->push(WorkerCountingJob::class);
        $this->queue->push(WorkerCountingJob::class);

        while ($this->worker->runNext()['status'] !== 'empty') {
        }

        self::assertSame(3, WorkerCountingJob::$handled);
    }

    public function testWorkerCrashLeavesJobInProcessing(): void
    {
        // Simulate: job was popped (moved to processing) but never acknowledged or failed
        $this->queue->push(WorkerCountingJob::class);
        $job = $this->queue->pop();
        self::assertNotNull($job);
        // "crash" — don't acknowledge or fail

        // Job sits in processing; future runNext() won't pick it up (no retry logic)
        self::assertCount(1, glob($this->tempDir . '/processing/*.job') ?: []);
        self::assertSame(0, $this->queue->size());
    }
}

// ---------------------------------------------------------------------------
// Test job fixtures
// ---------------------------------------------------------------------------

final class WorkerCountingJob implements Job
{
    public static int $handled = 0;

    public function handle(array $payload = []): void
    {
        self::$handled++;
    }
}

final class WorkerPayloadJob implements Job
{
    /** @var array<string, mixed> */
    public static array $lastPayload = [];

    public function handle(array $payload = []): void
    {
        self::$lastPayload = $payload;
    }
}

final class WorkerFailingJob implements Job
{
    public function handle(array $payload = []): void
    {
        throw new \RuntimeException('Job failed deliberately');
    }
}

final class WorkerNotAJob
{
    public function handle(array $payload = []): void {}
}
