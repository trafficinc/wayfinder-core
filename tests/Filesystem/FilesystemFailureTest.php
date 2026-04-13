<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Filesystem;

use PHPUnit\Framework\TestCase;
use Wayfinder\Cache\FileCache;
use Wayfinder\Logging\FileLogger;
use Wayfinder\Mail\FileMailer;
use Wayfinder\Mail\MailMessage;
use Wayfinder\Queue\FileQueue;
use Wayfinder\Session\FileSessionStore;
use Wayfinder\Session\Session;
use Wayfinder\Tests\Concerns\UsesTempDirectory;

/**
 * Filesystem failure tests: unwritable directories, files removed at runtime,
 * unwritable files, and corrupt / partial-write intermediate state.
 *
 * Tests that rely on chmod(0) are skipped when running as root because
 * root bypasses POSIX permission checks.
 */
final class FilesystemFailureTest extends TestCase
{
    use UsesTempDirectory;

    /** @var list<string> paths whose permissions must be restored in tearDown */
    private array $lockedPaths = [];

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
    }

    protected function tearDown(): void
    {
        // Restore permissions so UsesTempDirectory can delete everything.
        foreach ($this->lockedPaths as $path) {
            if (file_exists($path)) {
                chmod($path, 0777);
            }
        }
        $this->tearDownTempDirectory();
    }

    // =========================================================================
    // Section 1 — Unwritable parent directory → RuntimeException
    //
    // Each driver must throw RuntimeException when it cannot create its
    // working directory because the parent is not writable.
    // =========================================================================

    public function testFileCacheThrowsWhenCacheDirCannotBeCreated(): void
    {
        $this->skipIfRoot();

        $parent = $this->tempDir . '/locked';
        mkdir($parent, 0000, true);
        $this->lockedPaths[] = $parent;

        $cache = new FileCache($parent . '/cache');

        $this->expectException(\RuntimeException::class);
        $cache->put('key', 'value', 60);
    }

    public function testFileLoggerThrowsWhenLogDirCannotBeCreated(): void
    {
        $this->skipIfRoot();

        $parent = $this->tempDir . '/locked_log';
        mkdir($parent, 0000, true);
        $this->lockedPaths[] = $parent;

        $logger = new FileLogger($parent . '/sub/app.log');

        $this->expectException(\RuntimeException::class);
        $logger->info('should fail');
    }

    public function testFileMailerThrowsWhenMailDirCannotBeCreated(): void
    {
        $this->skipIfRoot();

        $parent = $this->tempDir . '/locked_mail';
        mkdir($parent, 0000, true);
        $this->lockedPaths[] = $parent;

        $mailer = new FileMailer($parent . '/outbox');

        $this->expectException(\RuntimeException::class);
        $mailer->send(new MailMessage('a@example.com', 'Hi', 'body'));
    }

    public function testFileSessionStoreThrowsWhenSessionDirCannotBeCreated(): void
    {
        $this->skipIfRoot();

        $parent = $this->tempDir . '/locked_sess';
        mkdir($parent, 0000, true);
        $this->lockedPaths[] = $parent;

        $store   = new FileSessionStore($parent . '/sessions');
        $session = new Session(bin2hex(random_bytes(20)));
        $session->put('x', 1);

        $this->expectException(\RuntimeException::class);
        $store->save($session);
    }

    public function testFileQueueThrowsOnConstructWhenQueueDirCannotBeCreated(): void
    {
        $this->skipIfRoot();

        $parent = $this->tempDir . '/locked_queue';
        mkdir($parent, 0000, true);
        $this->lockedPaths[] = $parent;

        $this->expectException(\RuntimeException::class);
        new FileQueue($parent . '/queue');
    }

    // =========================================================================
    // Section 2 — Directory removed at runtime → driver auto-recovers
    //
    // All five drivers re-create their working directory on every write, so
    // deleting the directory between two calls must be transparent to callers.
    // =========================================================================

    public function testFileCacheRecoversWhenCacheDirRemovedAtRuntime(): void
    {
        $dir   = $this->tempDir . '/cache_recover';
        $cache = new FileCache($dir);
        $cache->put('first', 'a', 60);

        // Simulate the directory disappearing (e.g. tmpfs unmount, admin rm -rf)
        $this->removeDirectory($dir);

        // put() must re-create the directory and succeed
        $cache->put('second', 'b', 60);

        self::assertSame('b', $cache->get('second'));
    }

    public function testFileLoggerRecoversWhenLogDirRemovedAtRuntime(): void
    {
        $logFile = $this->tempDir . '/log_recover/app.log';
        $logger  = new FileLogger($logFile);
        $logger->info('first entry');

        $this->removeDirectory(dirname($logFile));

        $logger->info('second entry');

        self::assertFileExists($logFile);
        self::assertStringContainsString('second entry', (string) file_get_contents($logFile));
    }

    public function testFileMailerRecoversWhenMailDirRemovedAtRuntime(): void
    {
        $dir    = $this->tempDir . '/mail_recover';
        $mailer = new FileMailer($dir);
        $mailer->send(new MailMessage('a@x.com', 'Hi', 'body'));

        $this->removeDirectory($dir);

        $mailer->send(new MailMessage('b@x.com', 'Hi again', 'body'));

        $files = glob($dir . '/*.mail') ?: [];
        self::assertCount(1, $files);
    }

    public function testFileSessionStoreRecoversWhenSessionDirRemovedAtRuntime(): void
    {
        $dir     = $this->tempDir . '/sess_recover';
        $store   = new FileSessionStore($dir);
        $session = new Session(bin2hex(random_bytes(20)));
        $session->put('a', 1);
        $store->save($session);

        $this->removeDirectory($dir);

        $session->put('b', 2);
        $store->save($session); // must re-create dir without throwing

        $file = $dir . '/' . $session->id() . '.json';
        self::assertFileExists($file);
    }

    public function testFileQueueRecoversWhenQueueDirRemovedAfterConstruction(): void
    {
        $dir   = $this->tempDir . '/queue_recover';
        $queue = new FileQueue($dir);
        $queue->push('Job', ['n' => 1]);

        // Remove the entire queue tree
        $this->removeDirectory($dir);

        // push() calls ensureDirectories() which must re-create the structure
        $queue->push('Job', ['n' => 2]);

        self::assertSame(1, $queue->size());
    }

    // =========================================================================
    // Section 3 — Unwritable existing file (silent failure modes)
    //
    // When the file exists but cannot be written, file_put_contents() returns
    // false.  Drivers that do not check that return value silently fail; tests
    // here document (and lock in) the observable behaviour for callers.
    // =========================================================================

    public function testFileCachePutSilentlyFailsWhenCacheFileIsUnwritable(): void
    {
        $this->skipIfRoot();

        $dir   = $this->tempDir . '/cache_ro';
        $cache = new FileCache($dir);
        $cache->put('key', 'original', 60);

        // Make the cache file read-only
        $file = $dir . '/' . sha1('key') . '.cache';
        chmod($file, 0444);
        $this->lockedPaths[] = $file;

        // put() will silently fail — no exception
        $cache->put('key', 'updated', 60);

        // The value on disk remains unchanged (original)
        self::assertSame('original', $cache->get('key'));
    }

    public function testFileLoggerAppendSilentlyFailsWhenLogFileIsUnwritable(): void
    {
        $this->skipIfRoot();

        $logFile = $this->tempDir . '/ro_log/app.log';
        $logger  = new FileLogger($logFile);
        $logger->info('first line');

        // Make log file read-only
        chmod($logFile, 0444);
        $this->lockedPaths[] = $logFile;

        // log() silently fails — no exception, file unchanged
        $logger->info('second line');

        $content = (string) file_get_contents($logFile);
        self::assertStringNotContainsString('second line', $content);
        self::assertStringContainsString('first line', $content);
    }

    // =========================================================================
    // Section 4 — Corrupt and partial-write intermediate state
    //
    // Files written with invalid content simulate truncation, torn writes, or
    // manually crafted corruption.  Drivers must recover gracefully.
    // =========================================================================

    // --- FileCache -----------------------------------------------------------

    public function testFileCacheIgnoresZeroByteFile(): void
    {
        $dir  = $this->tempDir . '/cache_corrupt';
        $file = $dir . '/' . sha1('k') . '.cache';
        mkdir($dir, 0777, true);
        file_put_contents($file, ''); // zero bytes

        $cache = new FileCache($dir);
        self::assertNull($cache->get('k'));
        self::assertFileDoesNotExist($file); // corrupt file deleted
    }

    public function testFileCacheIgnoresTruncatedSerializedData(): void
    {
        $dir  = $this->tempDir . '/cache_trunc';
        $file = $dir . '/' . sha1('k') . '.cache';
        mkdir($dir, 0777, true);

        // Write a valid serialized payload then truncate it halfway
        $full = serialize(['expires_at' => null, 'value' => 'hello']);
        file_put_contents($file, substr($full, 0, (int) (strlen($full) / 2)));

        $cache = new FileCache($dir);
        self::assertNull($cache->get('k'));
        self::assertFileDoesNotExist($file);
    }

    public function testFileCacheIgnoresBinaryGarbage(): void
    {
        $dir  = $this->tempDir . '/cache_bin';
        $file = $dir . '/' . sha1('k') . '.cache';
        mkdir($dir, 0777, true);
        file_put_contents($file, "\x00\x01\x02\x03\xff\xfe binary garbage \x80\x90");

        $cache = new FileCache($dir);
        self::assertNull($cache->get('k'));
        self::assertFileDoesNotExist($file);
    }

    public function testFileCacheIgnoresValidSerializedArrayMissingValueKey(): void
    {
        $dir  = $this->tempDir . '/cache_novalue';
        $file = $dir . '/' . sha1('k') . '.cache';
        mkdir($dir, 0777, true);
        file_put_contents($file, serialize(['expires_at' => null])); // 'value' absent

        $cache = new FileCache($dir);
        self::assertNull($cache->get('k'));
        self::assertFileDoesNotExist($file);
    }

    public function testFileCachePutOverwritesCorruptFile(): void
    {
        $dir  = $this->tempDir . '/cache_overwrite';
        $file = $dir . '/' . sha1('k') . '.cache';
        mkdir($dir, 0777, true);
        file_put_contents($file, 'not-serialized-data');

        $cache = new FileCache($dir);
        $cache->put('k', 'clean-value', 60);

        self::assertSame('clean-value', $cache->get('k'));
    }

    // --- FileQueue -----------------------------------------------------------

    public function testFileQueueAcknowledgeIsSilentWhenJobFileAlreadyGone(): void
    {
        $dir   = $this->tempDir . '/queue_ack';
        $queue = new FileQueue($dir);
        $queue->push('Job', []);
        $job = $queue->pop();
        self::assertIsArray($job);

        // Simulate another worker or cleanup deleting the processing file first
        unlink($job['__file']);

        // acknowledge() must not throw; queue remains at 0 pending
        $queue->acknowledge($job);
        self::assertSame(0, $queue->size());
    }

    public function testFileQueueFailIsSilentWhenJobFileAlreadyGone(): void
    {
        $dir   = $this->tempDir . '/queue_fail_gone';
        $queue = new FileQueue($dir);
        $queue->push('Job', []);
        $job = $queue->pop();
        self::assertIsArray($job);

        unlink($job['__file']);

        // fail() must not throw; no new failed entry (file was already gone)
        $queue->fail($job, new \RuntimeException('oops'));
        self::assertSame(0, $queue->failedSize());
    }

    public function testFileQueueMovesCorruptPendingJobToFailed(): void
    {
        $dir   = $this->tempDir . '/queue_corrupt';
        mkdir($dir . '/pending', 0777, true);
        mkdir($dir . '/processing', 0777, true);
        mkdir($dir . '/failed', 0777, true);

        // Plant a corrupt job file directly in pending/
        $name = sprintf('%s_%s.job', sprintf('%.6F', microtime(true)), bin2hex(random_bytes(4)));
        file_put_contents($dir . '/pending/' . $name, 'not-valid-serialized-data');

        $queue = new FileQueue($dir);
        $result = $queue->pop(); // must not throw; corrupt job → failed dir

        self::assertNull($result, 'pop() should return null for a corrupt job');
        self::assertSame(0, $queue->size(), 'Corrupt job must be removed from pending');
        self::assertSame(1, $queue->failedSize(), 'Corrupt job must be moved to failed');
    }

    public function testFileQueuePopReturnsNullWhenPendingFileDisappearsBeforeRename(): void
    {
        $dir   = $this->tempDir . '/queue_race';
        $queue = new FileQueue($dir);
        $queue->push('Job', []);

        // Grab the pending filename and delete it before pop() does the rename
        $files = glob($dir . '/pending/*.job') ?: [];
        self::assertCount(1, $files);
        unlink($files[0]);

        // pop() must return null, not throw
        self::assertNull($queue->pop());
    }

    // --- FileSessionStore ----------------------------------------------------

    public function testFileSessionStoreReturnsFreshSessionForZeroByteFile(): void
    {
        $dir = $this->tempDir . '/sess_corrupt';
        mkdir($dir, 0777, true);
        $id   = bin2hex(random_bytes(20));
        $file = $dir . '/' . $id . '.json';
        file_put_contents($file, ''); // zero bytes

        $store   = new FileSessionStore($dir);
        $session = $store->load($id);

        self::assertSame($id, $session->id());
        self::assertEmpty($session->all());
    }

    public function testFileSessionStoreReturnsFreshSessionForTruncatedJson(): void
    {
        $dir  = $this->tempDir . '/sess_trunc';
        mkdir($dir, 0777, true);
        $id   = bin2hex(random_bytes(20));
        $file = $dir . '/' . $id . '.json';
        file_put_contents($file, '{"key": "va'); // truncated JSON

        $store   = new FileSessionStore($dir);
        $session = $store->load($id);

        self::assertEmpty($session->all());
    }

    public function testFileSessionStoreSaveOverwritesCorruptFile(): void
    {
        $dir  = $this->tempDir . '/sess_overwrite';
        mkdir($dir, 0777, true);
        $id   = bin2hex(random_bytes(20));
        $file = $dir . '/' . $id . '.json';
        file_put_contents($file, 'not json at all');

        $store   = new FileSessionStore($dir);
        $session = new Session($id);
        $session->put('clean', true);
        $store->save($session);

        $decoded = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($decoded);
        self::assertTrue($decoded['clean']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function skipIfRoot(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            self::markTestSkipped('chmod(0) has no effect when running as root.');
        }
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (glob($path . '/{,.}*', GLOB_BRACE) ?: [] as $item) {
            $base = basename($item);
            if ($base === '.' || $base === '..') {
                continue;
            }
            is_dir($item) ? $this->removeDirectory($item) : unlink($item);
        }
        rmdir($path);
    }
}
