<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Queue;

use PHPUnit\Framework\TestCase;
use Wayfinder\Logging\NullLogger;
use Wayfinder\Queue\FileQueue;
use Wayfinder\Queue\Job;
use Wayfinder\Queue\JobDispatcher;
use Wayfinder\Support\Container;
use Wayfinder\Tests\Concerns\UsesTempDirectory;

final class JobDispatcherTest extends TestCase
{
    use UsesTempDirectory;

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
        DispatcherCountingJob::$handled = 0;
        DispatcherPayloadJob::$lastPayload = [];
    }

    protected function tearDown(): void
    {
        $this->tearDownTempDirectory();
    }

    public function testDispatchPushesOntoQueueWhenAsync(): void
    {
        $dispatcher = new JobDispatcher(new FileQueue($this->tempDir));

        $dispatcher->dispatch(DispatcherCountingJob::class, ['value' => 42]);

        self::assertCount(1, glob($this->tempDir . '/pending/*.job') ?: []);
        self::assertSame(0, DispatcherCountingJob::$handled);
    }

    public function testDispatchRunsJobImmediatelyWhenSyncEnabled(): void
    {
        $container = new Container();
        $container->instance(DispatcherCountingJob::class, new DispatcherCountingJob());
        $dispatcher = new JobDispatcher(new FileQueue($this->tempDir), $container, new NullLogger(), true);

        $dispatcher->dispatch(DispatcherCountingJob::class);

        self::assertSame(1, DispatcherCountingJob::$handled);
        self::assertCount(0, glob($this->tempDir . '/pending/*.job') ?: []);
    }

    public function testDispatchPassesPayloadWhenSyncEnabled(): void
    {
        $container = new Container();
        $container->instance(DispatcherPayloadJob::class, new DispatcherPayloadJob());
        $dispatcher = new JobDispatcher(new FileQueue($this->tempDir), $container, new NullLogger(), true);

        $dispatcher->dispatch(DispatcherPayloadJob::class, ['name' => 'queue-sync']);

        self::assertSame('queue-sync', DispatcherPayloadJob::$lastPayload['name'] ?? null);
    }
}

final class DispatcherCountingJob implements Job
{
    public static int $handled = 0;

    public function handle(array $payload = []): void
    {
        self::$handled++;
    }
}

final class DispatcherPayloadJob implements Job
{
    /** @var array<string, mixed> */
    public static array $lastPayload = [];

    public function handle(array $payload = []): void
    {
        self::$lastPayload = $payload;
    }
}
