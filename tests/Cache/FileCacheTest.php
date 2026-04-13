<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Wayfinder\Cache\FileCache;
use Wayfinder\Tests\Concerns\UsesTempDirectory;

final class FileCacheTest extends TestCase
{
    use UsesTempDirectory;

    private FileCache $cache;

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
        $this->cache = new FileCache($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->tearDownTempDirectory();
    }

    // -------------------------------------------------------------------------
    // Basic operations
    // -------------------------------------------------------------------------

    public function testGetReturnsDefaultForMissingKey(): void
    {
        self::assertNull($this->cache->get('missing'));
        self::assertSame('fallback', $this->cache->get('missing', 'fallback'));
    }

    public function testHasReturnsFalseForMissingKey(): void
    {
        self::assertFalse($this->cache->has('missing'));
    }

    public function testPutAndGet(): void
    {
        $this->cache->put('greeting', 'hello', 60);

        self::assertSame('hello', $this->cache->get('greeting'));
        self::assertTrue($this->cache->has('greeting'));
    }

    public function testPutOverwritesExistingKey(): void
    {
        $this->cache->put('key', 'first', 60);
        $this->cache->put('key', 'second', 60);

        self::assertSame('second', $this->cache->get('key'));
    }

    public function testForgetRemovesKey(): void
    {
        $this->cache->put('key', 'value', 60);
        $this->cache->forget('key');

        self::assertFalse($this->cache->has('key'));
        self::assertNull($this->cache->get('key'));
    }

    public function testForgetNonExistentKeyDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();
        $this->cache->forget('nonexistent');
    }

    // -------------------------------------------------------------------------
    // TTL / expiration
    // -------------------------------------------------------------------------

    public function testExpiredEntryReturnsDefault(): void
    {
        // Store with TTL of 1 second and back-date the file
        $this->cache->put('expired', 'old-value', 1);

        $file = $this->tempDir . '/' . sha1('expired') . '.cache';
        $payload = unserialize((string) file_get_contents($file));
        $payload['expires_at'] = time() - 1; // already expired
        file_put_contents($file, serialize($payload));

        self::assertNull($this->cache->get('expired'));
        self::assertFalse($this->cache->has('expired'));
    }

    public function testExpiredEntryIsDeletedFromDisk(): void
    {
        $this->cache->put('expired', 'old', 1);

        $file = $this->tempDir . '/' . sha1('expired') . '.cache';
        $payload = unserialize((string) file_get_contents($file));
        $payload['expires_at'] = time() - 1;
        file_put_contents($file, serialize($payload));

        $this->cache->get('expired');

        self::assertFileDoesNotExist($file);
    }

    public function testZeroTtlMeansNeverExpires(): void
    {
        $this->cache->put('permanent', 'value', 0);

        // Manually verify expires_at is null
        $file = $this->tempDir . '/' . sha1('permanent') . '.cache';
        $payload = unserialize((string) file_get_contents($file));
        self::assertNull($payload['expires_at']);

        self::assertSame('value', $this->cache->get('permanent'));
    }

    // -------------------------------------------------------------------------
    // remember()
    // -------------------------------------------------------------------------

    public function testRememberCallsCallbackOnCacheMiss(): void
    {
        $called = 0;
        $value = $this->cache->remember('key', 60, function () use (&$called): string {
            $called++;
            return 'computed';
        });

        self::assertSame('computed', $value);
        self::assertSame(1, $called);
    }

    public function testRememberDoesNotCallCallbackOnCacheHit(): void
    {
        $this->cache->put('key', 'cached', 60);
        $called = 0;

        $value = $this->cache->remember('key', 60, function () use (&$called): string {
            $called++;
            return 'computed';
        });

        self::assertSame('cached', $value);
        self::assertSame(0, $called);
    }

    public function testRememberStoresCallbackResult(): void
    {
        $this->cache->remember('key', 60, fn (): int => 42);

        self::assertSame(42, $this->cache->get('key'));
    }

    // -------------------------------------------------------------------------
    // Complex value types
    // -------------------------------------------------------------------------

    public function testCachesArray(): void
    {
        $this->cache->put('data', ['users' => [1, 2, 3]], 60);

        self::assertSame(['users' => [1, 2, 3]], $this->cache->get('data'));
    }

    public function testCachesNull(): void
    {
        $this->cache->put('nullval', null, 60);

        // has() uses read() which checks array_key_exists('value') — null is valid
        self::assertTrue($this->cache->has('nullval'));
        self::assertNull($this->cache->get('nullval'));
    }

    // -------------------------------------------------------------------------
    // Corrupt file handling
    // -------------------------------------------------------------------------

    public function testCorruptCacheFileReturnsDefault(): void
    {
        $file = $this->tempDir . '/' . sha1('corrupt') . '.cache';
        file_put_contents($file, 'not-valid-serialized-data');

        self::assertNull($this->cache->get('corrupt'));
    }

    public function testCorruptCacheFileIsDeletedOnRead(): void
    {
        $file = $this->tempDir . '/' . sha1('corrupt') . '.cache';
        file_put_contents($file, 'not-valid-serialized-data');

        $this->cache->get('corrupt');

        self::assertFileDoesNotExist($file);
    }

    public function testMissingValueKeyInPayloadIsCorrupt(): void
    {
        $file = $this->tempDir . '/' . sha1('bad-payload') . '.cache';
        file_put_contents($file, serialize(['expires_at' => null])); // no 'value' key

        self::assertNull($this->cache->get('bad-payload'));
        self::assertFileDoesNotExist($file);
    }

    // -------------------------------------------------------------------------
    // Directory creation
    // -------------------------------------------------------------------------

    public function testCreatesDirectoryOnFirstWrite(): void
    {
        $dir = $this->tempDir . '/nested/cache';
        $cache = new FileCache($dir);

        $cache->put('key', 'val', 60);

        self::assertDirectoryExists($dir);
    }
}
