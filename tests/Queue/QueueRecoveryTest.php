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

final class QueueRecoveryTest extends TestCase
{
    use UsesTempDirectory;

    private FileQueue $queue;
    private Container $container;

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
        $this->queue     = new FileQueue($this->tempDir);
        $this->container = new Container();
    }

    protected function tearDown(): void
    {
        RecoveryCountingJob::$handled = 0;
        RecoveryFailingJob::$attempts = 0;
        $this->tearDownTempDirectory();
    }

    // =========================================================================
    // Attempt tracking
    // =========================================================================

    public function testPopIncrementsAttemptCounterOnEachPick(): void
    {
        $this->queue->push('Job', ['id' => 1]);

        $job = $this->queue->pop();
        self::assertSame(1, $job['__attempts']);

        // Release it back to pending
        $this->queue->release($job);

        $job2 = $this->queue->pop();
        self::assertSame(2, $job2['__attempts']);
    }

    public function testAttemptCounterPersistsAcrossReleaseCycles(): void
    {
        $this->queue->push('Job', []);

        for ($i = 1; $i <= 5; $i++) {
            $job = $this->queue->pop();
            self::assertSame($i, $job['__attempts'], "Expected attempt {$i}");
            $this->queue->release($job);
        }
    }

    public function testFreshJobStartsAtAttemptOne(): void
    {
        $this->queue->push('Job', []);
        $job = $this->queue->pop();

        self::assertSame(1, $job['__attempts']);
    }

    // =========================================================================
    // Retry policy — Worker::runNext() with maxAttempts
    // =========================================================================

    public function testWorkerReleasesJobOnFirstFailureWhenRetriesRemain(): void
    {
        $worker = $this->makeWorker(maxAttempts: 3);
        $this->container->instance(RecoveryFailingJob::class, new RecoveryFailingJob());
        $this->queue->push(RecoveryFailingJob::class);

        $result = $worker->runNext();

        self::assertSame('released', $result['status']);
        self::assertSame(1, $result['attempts']);
        self::assertSame(1, $this->queue->size(), 'Job must return to pending after release');
        self::assertSame(0, $this->queue->failedSize());
    }

    public function testWorkerExhaustsRetriesAndMovesToFailed(): void
    {
        $worker = $this->makeWorker(maxAttempts: 3);
        $this->container->instance(RecoveryFailingJob::class, new RecoveryFailingJob());
        $this->queue->push(RecoveryFailingJob::class);

        // Three attempts: first two release, third fails permanently
        $r1 = $worker->runNext();
        $r2 = $worker->runNext();
        $r3 = $worker->runNext();

        self::assertSame('released', $r1['status']);
        self::assertSame('released', $r2['status']);
        self::assertSame('failed',   $r3['status']);

        self::assertSame(0, $this->queue->size());
        self::assertSame(1, $this->queue->failedSize());
        self::assertSame(0, $this->queue->processingSize());
    }

    public function testWorkerReportsCorrectAttemptNumberOnEachCycle(): void
    {
        $worker = $this->makeWorker(maxAttempts: 3);
        $this->container->instance(RecoveryFailingJob::class, new RecoveryFailingJob());
        $this->queue->push(RecoveryFailingJob::class);

        $r1 = $worker->runNext();
        $r2 = $worker->runNext();
        $r3 = $worker->runNext();

        self::assertSame(1, $r1['attempts']);
        self::assertSame(2, $r2['attempts']);
        self::assertSame(3, $r3['attempts']);
    }

    public function testMaxAttemptsOneGivesNoRetries(): void
    {
        $worker = $this->makeWorker(maxAttempts: 1);
        $this->container->instance(RecoveryFailingJob::class, new RecoveryFailingJob());
        $this->queue->push(RecoveryFailingJob::class);

        $result = $worker->runNext();

        self::assertSame('failed', $result['status']);
        self::assertSame(1, $this->queue->failedSize());
        self::assertSame(0, $this->queue->size());
    }

    public function testSuccessfulJobOnRetryIsAcknowledged(): void
    {
        // Job fails on first attempt, succeeds on second
        $job = new RecoveryFlakyJob(failTimes: 1);
        $this->container->instance(RecoveryFlakyJob::class, $job);
        $this->queue->push(RecoveryFlakyJob::class);

        $worker = $this->makeWorker(maxAttempts: 3);

        $r1 = $worker->runNext(); // attempt 1 — fails, released
        $r2 = $worker->runNext(); // attempt 2 — succeeds

        self::assertSame('released',  $r1['status']);
        self::assertSame('processed', $r2['status']);

        self::assertSame(0, $this->queue->size());
        self::assertSame(0, $this->queue->failedSize());
        self::assertSame(0, $this->queue->processingSize());
    }

    public function testReleasedJobGoesToBackOfQueue(): void
    {
        // Push two jobs; first one will fail and be released
        $this->container->instance(RecoveryFailingJob::class, new RecoveryFailingJob());
        $this->container->instance(RecoveryCountingJob::class, new RecoveryCountingJob());

        $this->queue->push(RecoveryFailingJob::class);
        usleep(2000);
        $this->queue->push(RecoveryCountingJob::class);

        $worker = $this->makeWorker(maxAttempts: 3);

        // First runNext: pops RecoveryFailingJob, releases it (back of queue)
        $r1 = $worker->runNext();
        self::assertSame('released', $r1['status']);

        // Second runNext: pops RecoveryCountingJob (it was ahead of the re-queued failing job)
        $r2 = $worker->runNext();
        self::assertSame('processed', $r2['status']);
        self::assertSame(1, RecoveryCountingJob::$handled);
    }

    // =========================================================================
    // Stuck processing jobs — processingSize()
    // =========================================================================

    public function testProcessingSizeReflectsInFlightJobs(): void
    {
        $this->queue->push('Job', []);
        $this->queue->push('Job', []);

        self::assertSame(0, $this->queue->processingSize());

        $j1 = $this->queue->pop();
        self::assertSame(1, $this->queue->processingSize());

        $j2 = $this->queue->pop();
        self::assertSame(2, $this->queue->processingSize());

        $this->queue->acknowledge($j1);
        self::assertSame(1, $this->queue->processingSize());

        $this->queue->acknowledge($j2);
        self::assertSame(0, $this->queue->processingSize());
    }

    public function testStuckJobRemainsInProcessingAfterWorkerCrash(): void
    {
        $this->queue->push('Job', []);
        $job = $this->queue->pop();
        self::assertNotNull($job);

        // Worker "crashes" — neither acknowledge() nor fail() nor release() is called
        unset($job); // gone, no cleanup

        self::assertSame(0, $this->queue->size());
        self::assertSame(1, $this->queue->processingSize());
        self::assertSame(0, $this->queue->failedSize());
    }

    public function testMultipleStuckJobsAccumulate(): void
    {
        $this->queue->push('A', []);
        $this->queue->push('B', []);
        $this->queue->push('C', []);

        $this->queue->pop();
        $this->queue->pop();

        self::assertSame(1, $this->queue->size());       // C still pending
        self::assertSame(2, $this->queue->processingSize()); // A and B stuck
    }

    // =========================================================================
    // recover() — rescue abandoned processing jobs
    // =========================================================================

    public function testRecoverReturnsZeroWhenNothingIsStuck(): void
    {
        $this->queue->push('Job', []);
        $job = $this->queue->pop();
        $this->queue->acknowledge($job);

        self::assertSame(0, $this->queue->recover(0));
    }

    public function testRecoverMovesOldProcessingJobBackToPending(): void
    {
        $this->queue->push('Job', ['id' => 1]);
        $job = $this->queue->pop();
        self::assertNotNull($job);

        // Back-date the processing file so it appears old
        $this->backdateFile($job['__file'], secondsAgo: 7200);

        $recovered = $this->queue->recover(olderThanSeconds: 3600);

        self::assertSame(1, $recovered);
        self::assertSame(1, $this->queue->size());
        self::assertSame(0, $this->queue->processingSize());
    }

    public function testRecoverIgnoresRecentProcessingJobs(): void
    {
        $this->queue->push('Job', []);
        $this->queue->pop(); // now in processing (mtime = now)

        // Recover only jobs older than 1 hour — the just-popped job is fresh
        $recovered = $this->queue->recover(olderThanSeconds: 3600);

        self::assertSame(0, $recovered);
        self::assertSame(0, $this->queue->size());
        self::assertSame(1, $this->queue->processingSize());
    }

    public function testRecoverMovesOnlyOldJobs(): void
    {
        $this->queue->push('Old', []);
        $this->queue->push('New', []);

        $oldJob = $this->queue->pop();
        usleep(2000);
        $newJob = $this->queue->pop();

        self::assertNotNull($oldJob);
        self::assertNotNull($newJob);

        $this->backdateFile($oldJob['__file'], secondsAgo: 7200);
        // $newJob file left with current mtime

        $recovered = $this->queue->recover(olderThanSeconds: 3600);

        self::assertSame(1, $recovered);
        self::assertSame(1, $this->queue->size());
        self::assertSame(1, $this->queue->processingSize()); // new job still stuck
    }

    public function testRecoverWithZeroThresholdMovesAllProcessingJobs(): void
    {
        $this->queue->push('A', []);
        $this->queue->push('B', []);
        $this->queue->push('C', []);

        $this->queue->pop();
        $this->queue->pop();
        $this->queue->pop();

        self::assertSame(3, $this->queue->processingSize());

        $recovered = $this->queue->recover(olderThanSeconds: 0);

        self::assertSame(3, $recovered);
        self::assertSame(3, $this->queue->size());
        self::assertSame(0, $this->queue->processingSize());
    }

    public function testRecoveredJobRetainsAttemptCount(): void
    {
        $this->queue->push('Job', ['data' => 'test']);
        $job = $this->queue->pop();
        self::assertSame(1, $job['__attempts']);

        // Simulate crash — back-date and recover
        $this->backdateFile($job['__file'], secondsAgo: 7200);
        $this->queue->recover(olderThanSeconds: 3600);

        // Pop again — attempt counter must now be 2
        $job2 = $this->queue->pop();
        self::assertSame(2, $job2['__attempts']);
    }

    public function testRecoveredJobIsRunnableByWorker(): void
    {
        $this->container->instance(RecoveryCountingJob::class, new RecoveryCountingJob());
        $this->queue->push(RecoveryCountingJob::class, []);

        // Simulate worker crash after pop
        $job = $this->queue->pop();
        self::assertNotNull($job);
        $this->backdateFile($job['__file'], secondsAgo: 7200);

        // Operator runs recovery
        $this->queue->recover(olderThanSeconds: 3600);
        self::assertSame(1, $this->queue->size());

        // Worker picks it up and processes it successfully
        $worker = $this->makeWorker(maxAttempts: 3);
        $result = $worker->runNext();

        self::assertSame('processed', $result['status']);
        self::assertSame(1, RecoveryCountingJob::$handled);
    }

    public function testRecoverIsIdempotentWhenCalledTwice(): void
    {
        $this->queue->push('Job', []);
        $job = $this->queue->pop();
        $this->backdateFile($job['__file'], secondsAgo: 7200);

        $first  = $this->queue->recover(olderThanSeconds: 3600);
        $second = $this->queue->recover(olderThanSeconds: 3600);

        // File is now in pending, not processing — second recover finds nothing
        self::assertSame(1, $first);
        self::assertSame(0, $second);
        self::assertSame(1, $this->queue->size());
    }

    // =========================================================================
    // Full crash-and-recover scenario
    // =========================================================================

    public function testFullCrashAndRecoverCycle(): void
    {
        $this->container->instance(RecoveryCountingJob::class, new RecoveryCountingJob());

        // 5 jobs pushed
        for ($i = 0; $i < 5; $i++) {
            $this->queue->push(RecoveryCountingJob::class, ['i' => $i]);
        }

        // Worker picks up 3 jobs but crashes mid-batch (none acknowledged)
        $stuck = [];
        for ($i = 0; $i < 3; $i++) {
            $j = $this->queue->pop();
            self::assertNotNull($j);
            $stuck[] = $j;
        }

        self::assertSame(2, $this->queue->size());
        self::assertSame(3, $this->queue->processingSize());

        // Back-date the stuck jobs
        foreach ($stuck as $j) {
            $this->backdateFile($j['__file'], secondsAgo: 7200);
        }

        // Operator runs recovery
        $recovered = $this->queue->recover(olderThanSeconds: 3600);
        self::assertSame(3, $recovered);
        self::assertSame(5, $this->queue->size());
        self::assertSame(0, $this->queue->processingSize());

        // Worker drains the full queue successfully
        $worker = $this->makeWorker(maxAttempts: 5);
        while ($worker->runNext()['status'] !== 'empty') {
        }

        self::assertSame(5, RecoveryCountingJob::$handled);
        self::assertSame(0, $this->queue->size());
        self::assertSame(0, $this->queue->failedSize());
    }

    // =========================================================================
    // release() edge cases
    // =========================================================================

    public function testReleaseIsSilentWhenJobFileAlreadyGone(): void
    {
        $this->queue->push('Job', []);
        $job = $this->queue->pop();
        self::assertIsArray($job);

        unlink($job['__file']);

        $this->queue->release($job); // must not throw

        self::assertSame(0, $this->queue->size());
    }

    public function testReleaseDoesNotWriteToFailedQueue(): void
    {
        $this->queue->push('Job', []);
        $job = $this->queue->pop();
        $this->queue->release($job);

        self::assertSame(0, $this->queue->failedSize());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeWorker(int $maxAttempts = 3): Worker
    {
        return new Worker($this->queue, $this->container, new NullLogger(), maxAttempts: $maxAttempts);
    }

    private function backdateFile(string $path, int $secondsAgo): void
    {
        $mtime = time() - $secondsAgo;
        touch($path, $mtime, $mtime);
    }
}

// ---------------------------------------------------------------------------
// Fixture job classes
// ---------------------------------------------------------------------------

final class RecoveryCountingJob implements Job
{
    public static int $handled = 0;

    public function handle(array $payload = []): void
    {
        self::$handled++;
    }
}

final class RecoveryFailingJob implements Job
{
    public static int $attempts = 0;

    public function handle(array $payload = []): void
    {
        self::$attempts++;
        throw new \RuntimeException('Deliberate failure #' . self::$attempts);
    }
}

/**
 * Fails for the first $failTimes attempts, then succeeds.
 */
final class RecoveryFlakyJob implements Job
{
    private int $callCount = 0;

    public function __construct(private readonly int $failTimes = 1) {}

    public function handle(array $payload = []): void
    {
        $this->callCount++;

        if ($this->callCount <= $this->failTimes) {
            throw new \RuntimeException("Flaky failure attempt {$this->callCount}");
        }
    }
}
