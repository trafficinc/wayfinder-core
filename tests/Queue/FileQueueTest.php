<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Queue;

use PHPUnit\Framework\TestCase;
use Wayfinder\Queue\FileQueue;
use Wayfinder\Tests\Concerns\UsesTempDirectory;

final class FileQueueTest extends TestCase
{
    use UsesTempDirectory;

    private FileQueue $queue;

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
        $this->queue = new FileQueue($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->tearDownTempDirectory();
    }

    public function testPushCreatesFileInPendingDirectory(): void
    {
        $this->queue->push('App\Jobs\SendEmail', ['to' => 'user@example.com']);

        $files = glob($this->tempDir . '/pending/*.job') ?: [];
        self::assertCount(1, $files);
    }

    public function testSizeReflectsPendingCount(): void
    {
        self::assertSame(0, $this->queue->size());

        $this->queue->push('App\Jobs\ExampleJob');
        $this->queue->push('App\Jobs\ExampleJob');

        self::assertSame(2, $this->queue->size());
    }

    public function testPopReturnsNullWhenEmpty(): void
    {
        self::assertNull($this->queue->pop());
    }

    public function testPopReturnsJobData(): void
    {
        $this->queue->push('App\Jobs\SendEmail', ['to' => 'user@example.com']);

        $job = $this->queue->pop();

        self::assertNotNull($job);
        self::assertSame('App\Jobs\SendEmail', $job['job']);
        self::assertSame(['to' => 'user@example.com'], $job['payload']);
        self::assertArrayHasKey('__file', $job);
    }

    public function testPopMovesJobFromPendingToProcessing(): void
    {
        $this->queue->push('App\Jobs\ExampleJob');

        $this->queue->pop();

        self::assertCount(0, glob($this->tempDir . '/pending/*.job') ?: []);
        self::assertCount(1, glob($this->tempDir . '/processing/*.job') ?: []);
    }

    public function testPopDoesNotReturnSameJobTwice(): void
    {
        $this->queue->push('App\Jobs\ExampleJob');

        $first = $this->queue->pop();
        $second = $this->queue->pop();

        self::assertNotNull($first);
        self::assertNull($second);
    }

    public function testPopReturnsJobsInFifoOrder(): void
    {
        $this->queue->push('App\Jobs\First', ['order' => 1]);
        usleep(1000); // ensure distinct timestamps
        $this->queue->push('App\Jobs\Second', ['order' => 2]);

        $first = $this->queue->pop();
        $second = $this->queue->pop();

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame(1, $first['payload']['order']);
        self::assertSame(2, $second['payload']['order']);
    }

    public function testAcknowledgeDeletesProcessingFile(): void
    {
        $this->queue->push('App\Jobs\ExampleJob');
        $job = $this->queue->pop();
        self::assertNotNull($job);

        $this->queue->acknowledge($job);

        self::assertFileDoesNotExist($job['__file']);
    }

    public function testAcknowledgeIgnoresMissingFile(): void
    {
        $this->expectNotToPerformAssertions();
        $this->queue->acknowledge(['__file' => '/nonexistent/path/job.job', 'job' => 'X', 'payload' => []]);
    }

    public function testFailMovesJobToFailedDirectory(): void
    {
        $this->queue->push('App\Jobs\ExampleJob');
        $job = $this->queue->pop();
        self::assertNotNull($job);

        $this->queue->fail($job, new \RuntimeException('Something broke'));

        self::assertCount(0, glob($this->tempDir . '/processing/*.job') ?: []);
        self::assertCount(1, glob($this->tempDir . '/failed/*.failed') ?: []);
    }

    public function testFailRecordsExceptionDetails(): void
    {
        $this->queue->push('App\Jobs\ExampleJob', ['key' => 'val']);
        $job = $this->queue->pop();
        self::assertNotNull($job);

        $this->queue->fail($job, new \RuntimeException('Disk full'));

        $file = (glob($this->tempDir . '/failed/*.failed') ?: [])[0];
        $payload = unserialize((string) file_get_contents($file));

        self::assertSame('Disk full', $payload['error']);
        self::assertSame(\RuntimeException::class, $payload['exception']);
        self::assertSame('App\Jobs\ExampleJob', $payload['job']);
    }

    public function testFailedSizeCountsFailedJobs(): void
    {
        self::assertSame(0, $this->queue->failedSize());

        $this->queue->push('App\Jobs\ExampleJob');
        $job = $this->queue->pop();
        self::assertNotNull($job);
        $this->queue->fail($job, new \RuntimeException('oops'));

        self::assertSame(1, $this->queue->failedSize());
    }

    public function testCorruptJobFileIsMovedToFailed(): void
    {
        // Write a corrupt (non-serialized) file directly into pending
        file_put_contents($this->tempDir . '/pending/corrupt.job', 'not-valid-serialized-data');

        $result = $this->queue->pop();

        self::assertNull($result);
        self::assertCount(0, glob($this->tempDir . '/pending/*.job') ?: []);
        self::assertCount(1, glob($this->tempDir . '/failed/*.failed') ?: []);
    }

    public function testCustomFailedPath(): void
    {
        $failedDir = $this->tempDir . '/custom_failed';
        $queue = new FileQueue($this->tempDir, $failedDir);

        $queue->push('App\Jobs\ExampleJob');
        $job = $queue->pop();
        self::assertNotNull($job);
        $queue->fail($job, new \RuntimeException('err'));

        self::assertCount(1, glob($failedDir . '/*.failed') ?: []);
    }

    public function testFailIgnoresMissingProcessingFile(): void
    {
        $this->expectNotToPerformAssertions();
        $this->queue->fail(
            ['__file' => '/nonexistent/path.job', 'job' => 'X', 'payload' => []],
            new \RuntimeException('x'),
        );
    }

    public function testQueueCreatesDirectoriesOnDemand(): void
    {
        $dir = $this->tempDir . '/nested/queue';
        $queue = new FileQueue($dir);

        $queue->push('App\Jobs\ExampleJob');

        self::assertDirectoryExists($dir . '/pending');
        self::assertDirectoryExists($dir . '/processing');
        self::assertDirectoryExists($dir . '/failed');
    }
}
