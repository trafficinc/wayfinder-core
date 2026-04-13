<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Session;

use PHPUnit\Framework\TestCase;
use Wayfinder\Session\FileSessionStore;
use Wayfinder\Session\Session;
use Wayfinder\Tests\Concerns\UsesTempDirectory;

final class FileSessionStoreTest extends TestCase
{
    use UsesTempDirectory;

    private FileSessionStore $store;

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
        $this->store = new FileSessionStore($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->tearDownTempDirectory();
    }

    public function testLoadReturnsFreshSessionForMissingFile(): void
    {
        $session = $this->store->load('nonexistent-id');

        self::assertSame('nonexistent-id', $session->id());
        self::assertFalse($session->exists());
        self::assertSame([], $session->all());
    }

    public function testLoadReturnsFreshSessionForCorruptFile(): void
    {
        file_put_contents($this->tempDir . '/corrupt.json', 'not valid json {{{{');

        $session = $this->store->load('corrupt');

        self::assertFalse($session->exists());
        self::assertSame([], $session->all());
    }

    public function testLoadReturnsFreshSessionForNonArrayJsonPayload(): void
    {
        file_put_contents($this->tempDir . '/scalar.json', json_encode('tampered'));

        $session = $this->store->load('scalar');

        self::assertFalse($session->exists());
        self::assertSame([], $session->all());
    }

    public function testSaveAndLoadRoundTrip(): void
    {
        $session = new Session('test-id');
        $session->put('user_id', 99);
        $session->put('role', 'admin');

        $this->store->save($session);
        $loaded = $this->store->load('test-id');

        self::assertSame(99, $loaded->get('user_id'));
        self::assertSame('admin', $loaded->get('role'));
        self::assertTrue($loaded->exists());
    }

    public function testSaveCreatesDirectoryIfMissing(): void
    {
        $nestedPath = $this->tempDir . '/deep/sessions';
        $store = new FileSessionStore($nestedPath);
        $session = new Session('test-id');
        $session->put('key', 'val');

        $store->save($session);

        self::assertDirectoryExists($nestedPath);
        self::assertFileExists($nestedPath . '/test-id.json');
    }

    public function testSaveDeletesPreviousSessionFileAfterRegenerate(): void
    {
        $session = new Session('old-id');
        $session->put('key', 'value');
        $this->store->save($session);

        self::assertFileExists($this->tempDir . '/old-id.json');

        $session->regenerate(); // sets previousId = 'old-id', changes id
        $this->store->save($session);

        self::assertFileDoesNotExist($this->tempDir . '/old-id.json');
        self::assertFileExists($this->tempDir . '/' . $session->id() . '.json');
    }

    public function testSaveCallsSyncAfterSave(): void
    {
        $session = new Session('id');
        $session->put('x', 1);

        self::assertTrue($session->isDirty());

        $this->store->save($session);

        self::assertFalse($session->isDirty());
        self::assertTrue($session->exists());
    }

    public function testDeleteRemovesFile(): void
    {
        $session = new Session('to-delete');
        $session->put('x', 1);
        $this->store->save($session);

        self::assertFileExists($this->tempDir . '/to-delete.json');

        $this->store->delete('to-delete');

        self::assertFileDoesNotExist($this->tempDir . '/to-delete.json');
    }

    public function testDeleteIgnoresMissingFile(): void
    {
        $this->expectNotToPerformAssertions();
        $this->store->delete('nonexistent');
    }

    public function testSessionDataIsPersistedAsJson(): void
    {
        $session = new Session('json-test');
        $session->put('name', 'Wayfinder');

        $this->store->save($session);

        $raw = file_get_contents($this->tempDir . '/json-test.json');
        $decoded = json_decode((string) $raw, true);

        self::assertIsArray($decoded);
        self::assertSame('Wayfinder', $decoded['name']);
    }
}
