<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Concurrency;

use PHPUnit\Framework\TestCase;
use Wayfinder\Tests\Concerns\UsesTempDirectory;

/**
 * Concurrency tests that spawn real parallel PHP processes to stress-test
 * the shared-file drivers under simultaneous access.
 *
 * Each test writes a small PHP worker script to the temp dir, spawns N
 * processes via proc_open(), waits for all to exit, then asserts invariants
 * over the resulting filesystem state.
 */
final class ConcurrencyTest extends TestCase
{
    use UsesTempDirectory;

    private string $autoload;

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
        $this->autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    }

    protected function tearDown(): void
    {
        $this->tearDownTempDirectory();
    }

    // =========================================================================
    // Queue: parallel workers pop from the same queue
    // =========================================================================

    /**
     * 20 jobs pushed; 5 workers drain the queue in parallel.
     * Because FileQueue::pop() uses rename() (atomic on POSIX), each job
     * must be processed by exactly one worker — no duplicates, no losses.
     */
    public function testParallelWorkersProcessEachJobExactlyOnce(): void
    {
        $queueDir  = $this->tempDir . '/queue';
        $outputDir = $this->tempDir . '/output';
        mkdir($outputDir, 0777, true);

        // Pre-push 20 jobs using a single-process bootstrap so files are
        // sorted correctly before workers start.
        $pushScript = $this->writeScript('push_jobs.php', <<<PHP
        <?php
        require {$this->q($this->autoload)};
        \$queue = new \Wayfinder\Queue\FileQueue({$this->q($queueDir)});
        for (\$i = 1; \$i <= 20; \$i++) {
            usleep(1000); // ensure distinct microsecond timestamps
            \$queue->push('TestJob', ['id' => \$i]);
        }
        PHP);
        $this->runScript($pushScript);

        // Worker: drain until empty, write each job id to output/<id>.done
        $workerScript = $this->writeScript('queue_worker.php', <<<PHP
        <?php
        require {$this->q($this->autoload)};
        \$queue   = new \Wayfinder\Queue\FileQueue({$this->q($queueDir)});
        \$outDir  = {$this->q($outputDir)};
        \$maxIter = 50;
        while (\$maxIter-- > 0) {
            \$job = \$queue->pop();
            if (\$job === null) {
                usleep(5000);
                continue;
            }
            \$id = (int) \$job['payload']['id'];
            // Write atomically so concurrent workers don't collide on the output file
            file_put_contents(\$outDir . '/' . \$id . '.done', (string) \$id);
            \$queue->acknowledge(\$job);
        }
        PHP);

        $this->runParallel($workerScript, workers: 5, timeoutSeconds: 15);

        // Every job id 1..20 must appear in output exactly once
        $done = glob($outputDir . '/*.done') ?: [];
        self::assertCount(20, $done, 'Every job must be acknowledged by exactly one worker.');

        $ids = array_map(static fn (string $f): int => (int) file_get_contents($f), $done);
        sort($ids);
        self::assertSame(range(1, 20), $ids, 'All 20 job IDs must appear with no duplicates.');

        // Processing dir must be empty (no stuck jobs)
        $stuck = glob($queueDir . '/processing/*.job') ?: [];
        self::assertEmpty($stuck, 'No jobs should remain stuck in processing.');
    }

    /**
     * A job that a worker pops must not be visible to any other worker:
     * only one rename() wins; the rest silently return null.
     */
    public function testOnlyOneWorkerCanPopTheSameJob(): void
    {
        $queueDir  = $this->tempDir . '/queue2';
        $outputDir = $this->tempDir . '/output2';
        mkdir($outputDir, 0777, true);

        // Push a single job
        $pushScript = $this->writeScript('push_one.php', <<<PHP
        <?php
        require {$this->q($this->autoload)};
        \$queue = new \Wayfinder\Queue\FileQueue({$this->q($queueDir)});
        \$queue->push('TestJob', ['id' => 1]);
        PHP);
        $this->runScript($pushScript);

        // 10 workers all race to pop that one job
        $workerScript = $this->writeScript('single_pop_worker.php', <<<PHP
        <?php
        require {$this->q($this->autoload)};
        \$queue   = new \Wayfinder\Queue\FileQueue({$this->q($queueDir)});
        \$outDir  = {$this->q($outputDir)};
        \$job = \$queue->pop();
        if (\$job !== null) {
            \$file = \$outDir . '/' . getmypid() . '.popped';
            file_put_contents(\$file, '1');
            \$queue->acknowledge(\$job);
        }
        PHP);

        $this->runParallel($workerScript, workers: 10, timeoutSeconds: 10);

        $popped = glob($outputDir . '/*.popped') ?: [];
        self::assertCount(1, $popped, 'Exactly one worker must pop the single job.');
    }

    // =========================================================================
    // Session: concurrent writes to the same session file
    // =========================================================================

    /**
     * N parallel "requests" each load the same session, add a unique key,
     * and save.  The final file must be valid JSON — not zero-length, not
     * truncated, not garbled — even though file_put_contents has no locking.
     */
    public function testConcurrentSessionWritesProduceValidJson(): void
    {
        $sessionDir = $this->tempDir . '/sessions';
        $sessionId  = bin2hex(random_bytes(20));
        mkdir($sessionDir, 0777, true);

        $writerScript = $this->writeScript('session_writer.php', <<<PHP
        <?php
        require {$this->q($this->autoload)};
        \$store     = new \Wayfinder\Session\FileSessionStore({$this->q($sessionDir)});
        \$sessionId = {$this->q($sessionId)};
        \$session   = \$store->load(\$sessionId);
        \$session->put('pid_' . getmypid(), getmypid());
        \$store->save(\$session);
        PHP);

        $this->runParallel($writerScript, workers: 10, timeoutSeconds: 10);

        $file = $sessionDir . '/' . $sessionId . '.json';
        self::assertFileExists($file, 'Session file must exist after concurrent writes.');

        $size = filesize($file);
        self::assertGreaterThan(0, $size, 'Session file must not be empty.');

        $decoded = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($decoded, 'Session file must contain valid JSON after concurrent writes.');
    }

    /**
     * After N concurrent writes the session file must not be zero bytes,
     * even in the worst-case interleaving. This guards against a truncation
     * regression if file_put_contents ever changes its write behaviour.
     */
    public function testConcurrentSessionWritesNeverProduceZeroByteFile(): void
    {
        $sessionDir = $this->tempDir . '/sessions2';
        $sessionId  = bin2hex(random_bytes(20));
        mkdir($sessionDir, 0777, true);

        $writerScript = $this->writeScript('session_zero_writer.php', <<<PHP
        <?php
        require {$this->q($this->autoload)};
        \$store     = new \Wayfinder\Session\FileSessionStore({$this->q($sessionDir)});
        \$sessionId = {$this->q($sessionId)};
        for (\$i = 0; \$i < 5; \$i++) {
            \$session = \$store->load(\$sessionId);
            \$session->put('k' . \$i . '_' . getmypid(), \$i);
            \$store->save(\$session);
        }
        PHP);

        $this->runParallel($writerScript, workers: 8, timeoutSeconds: 10);

        $file = $sessionDir . '/' . $sessionId . '.json';
        self::assertFileExists($file);
        self::assertGreaterThan(0, filesize($file));
    }

    // =========================================================================
    // Cache: concurrent remember() calls
    // =========================================================================

    /**
     * N processes simultaneously call remember() on the same key with a
     * callback that returns a fixed value.  Every process must get back the
     * correct value; the final cache entry must be valid and contain that value.
     *
     * This does NOT assert the callback is called only once — FileCache has no
     * mutex for the check-then-act window.  What it does assert is that no
     * process gets back a corrupt or wrong value (eventual consistency).
     */
    public function testConcurrentRememberCallsAllReturnCorrectValue(): void
    {
        $cacheDir  = $this->tempDir . '/cache';
        $outputDir = $this->tempDir . '/cache_output';
        mkdir($outputDir, 0777, true);

        $workerScript = $this->writeScript('cache_remember_worker.php', <<<PHP
        <?php
        require {$this->q($this->autoload)};
        \$cache    = new \Wayfinder\Cache\FileCache({$this->q($cacheDir)});
        \$outDir   = {$this->q($outputDir)};
        \$value = \$cache->remember('shared-key', 60, static function (): string {
            usleep(random_int(0, 3000)); // stagger slightly
            return 'expected-value';
        });
        // Write what we got so the parent can check
        file_put_contents(\$outDir . '/' . getmypid() . '.result', \$value);
        PHP);

        $this->runParallel($workerScript, workers: 10, timeoutSeconds: 10);

        // Every worker must have received the correct value
        $results = glob($outputDir . '/*.result') ?: [];
        self::assertNotEmpty($results, 'Each worker must write a result file.');

        foreach ($results as $resultFile) {
            self::assertSame(
                'expected-value',
                file_get_contents($resultFile),
                'Every concurrent remember() call must return the correct value.',
            );
        }

        // The key must be cached after all workers have run
        $cache = new \Wayfinder\Cache\FileCache($cacheDir);
        self::assertSame('expected-value', $cache->get('shared-key'));
    }

    /**
     * Concurrent put() on the same key must leave a valid, readable cache
     * entry — not a zero-byte or corrupt file.
     */
    public function testConcurrentCachePutsProduceReadableEntry(): void
    {
        $cacheDir = $this->tempDir . '/cache2';

        $workerScript = $this->writeScript('cache_put_worker.php', <<<PHP
        <?php
        require {$this->q($this->autoload)};
        \$cache = new \Wayfinder\Cache\FileCache({$this->q($cacheDir)});
        for (\$i = 0; \$i < 10; \$i++) {
            \$cache->put('hot-key', 'value-from-' . getmypid(), 60);
            usleep(random_int(0, 500));
        }
        PHP);

        $this->runParallel($workerScript, workers: 8, timeoutSeconds: 10);

        $cache = new \Wayfinder\Cache\FileCache($cacheDir);
        $value = $cache->get('hot-key');

        self::assertNotNull($value, 'Cache entry must survive concurrent writes.');
        self::assertStringStartsWith('value-from-', (string) $value);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Write a PHP script to the temp directory and return its path.
     */
    private function writeScript(string $name, string $body): string
    {
        $path = $this->tempDir . '/' . $name;
        // Dedent heredoc indentation (leading spaces from the call-site indent)
        $lines = explode("\n", $body);
        $stripped = array_map(static fn (string $l): string => preg_replace('/^        /', '', $l) ?? $l, $lines);
        file_put_contents($path, implode("\n", $stripped));

        return $path;
    }

    /**
     * Run a single PHP script and wait for it to exit.
     */
    private function runScript(string $scriptPath, int $timeoutSeconds = 10): void
    {
        $process = proc_open(
            [PHP_BINARY, $scriptPath],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if ($process === false) {
            $this->fail("Failed to start PHP process for {$scriptPath}.");
        }

        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $deadline = time() + $timeoutSeconds;
        while (time() < $deadline) {
            $status = proc_get_status($process);
            if (! $status['running']) {
                break;
            }
            usleep(10000);
        }

        proc_close($process);
    }

    /**
     * Spawn $workers parallel PHP processes all running $scriptPath, then
     * wait for all of them to exit within $timeoutSeconds.
     *
     * @param array<int, resource> $processes
     */
    private function runParallel(string $scriptPath, int $workers = 5, int $timeoutSeconds = 15): void
    {
        $processes = [];
        $pipes     = [];

        for ($i = 0; $i < $workers; $i++) {
            $p = proc_open(
                [PHP_BINARY, $scriptPath],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipeSet,
            );

            if ($p === false) {
                $this->fail("Failed to spawn worker process #{$i}.");
            }

            $processes[] = $p;
            $pipes[]     = $pipeSet;
        }

        // Close pipes so we don't deadlock on buffered output
        foreach ($pipes as $pipeSet) {
            fclose($pipeSet[1]);
            fclose($pipeSet[2]);
        }

        // Poll until all processes exit or timeout
        $deadline = time() + $timeoutSeconds;
        $remaining = $processes;

        while (! empty($remaining) && time() < $deadline) {
            foreach ($remaining as $key => $process) {
                $status = proc_get_status($process);
                if (! $status['running']) {
                    proc_close($process);
                    unset($remaining[$key]);
                }
            }

            if (! empty($remaining)) {
                usleep(10000); // 10 ms poll interval
            }
        }

        if (! empty($remaining)) {
            foreach ($remaining as $process) {
                proc_terminate($process);
                proc_close($process);
            }
            $this->fail("Worker processes did not finish within {$timeoutSeconds}s.");
        }
    }

    /**
     * Quote a string for safe embedding in a PHP script literal.
     */
    private function q(string $value): string
    {
        return "'" . addslashes($value) . "'";
    }
}
