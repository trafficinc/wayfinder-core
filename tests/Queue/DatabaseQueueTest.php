<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Queue;

use PHPUnit\Framework\TestCase;
use Wayfinder\Database\Database;
use Wayfinder\Queue\DatabaseQueue;
use Wayfinder\Tests\Concerns\UsesTempDirectory;

final class DatabaseQueueTest extends TestCase
{
    use UsesTempDirectory;

    private Database $database;
    private DatabaseQueue $queue;

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
        $this->database = new Database([
            'driver' => 'sqlite',
            'path' => $this->tempDir . '/queue.sqlite',
        ]);
        $this->database->statement(<<<'SQL'
            CREATE TABLE jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job TEXT NOT NULL,
                payload TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL,
                queued_at TEXT NULL,
                processing_started_at TEXT NULL,
                failed_at TEXT NULL,
                error TEXT NULL
            )
        SQL);
        $this->queue = new DatabaseQueue($this->database);
    }

    protected function tearDown(): void
    {
        $this->tearDownTempDirectory();
    }

    public function testPushStoresPendingJob(): void
    {
        $this->queue->push('ExampleJob', ['alpha' => 'beta']);

        self::assertSame(1, $this->queue->size());
        self::assertSame(0, $this->queue->processingSize());
    }

    public function testPopMovesJobToProcessingAndReturnsPayload(): void
    {
        $this->queue->push('ExampleJob', ['alpha' => 'beta']);

        $job = $this->queue->pop();

        self::assertSame('ExampleJob', $job['job'] ?? null);
        self::assertSame('beta', $job['payload']['alpha'] ?? null);
        self::assertSame(0, $this->queue->size());
        self::assertSame(1, $this->queue->processingSize());
    }

    public function testAcknowledgeDeletesProcessedJob(): void
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
        self::assertSame('boom', $this->database->table('jobs')->where('status', 'failed')->value('error'));
    }

    public function testRecoverMovesStaleProcessingJobsBackToPending(): void
    {
        $this->queue->push('ExampleJob');
        $job = $this->queue->pop();

        self::assertIsArray($job);

        $this->database
            ->table('jobs')
            ->prepareUpdate([
                'processing_started_at' => date('c', time() - 7200),
            ])
            ->where('id', $job['__id'])
            ->execute();

        self::assertSame(1, $this->queue->recover(3600));
        self::assertSame(1, $this->queue->size());
        self::assertSame(0, $this->queue->processingSize());
    }
}
