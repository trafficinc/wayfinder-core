<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Session;

use PHPUnit\Framework\TestCase;
use Wayfinder\Database\Database;
use Wayfinder\Session\DatabaseSessionStore;
use Wayfinder\Session\Session;
use Wayfinder\Tests\Concerns\UsesTempDirectory;

final class DatabaseSessionStoreTest extends TestCase
{
    use UsesTempDirectory;

    private DatabaseSessionStore $store;

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
        $database = new Database([
            'driver' => 'sqlite',
            'path' => $this->tempDir . '/sessions.sqlite',
        ]);
        $database->statement('CREATE TABLE sessions (id TEXT PRIMARY KEY, payload TEXT NOT NULL, last_activity INTEGER NOT NULL)');
        $this->store = new DatabaseSessionStore($database);
    }

    protected function tearDown(): void
    {
        $this->tearDownTempDirectory();
    }

    public function testLoadReturnsFreshSessionForMissingRow(): void
    {
        $session = $this->store->load('missing-id');

        self::assertSame('missing-id', $session->id());
        self::assertFalse($session->exists());
        self::assertSame([], $session->all());
    }

    public function testSaveAndLoadRoundTrip(): void
    {
        $session = new Session('db-session-id');
        $session->put('user_id', 42);
        $session->put('role', 'admin');

        $this->store->save($session);
        $loaded = $this->store->load('db-session-id');

        self::assertSame(42, $loaded->get('user_id'));
        self::assertSame('admin', $loaded->get('role'));
        self::assertTrue($loaded->exists());
    }

    public function testSaveDeletesPreviousRowAfterRegenerate(): void
    {
        $session = new Session('old-db-id');
        $session->put('key', 'value');
        $this->store->save($session);

        $session->regenerate();
        $this->store->save($session);

        self::assertFalse($this->store->load('old-db-id')->exists());
        self::assertTrue($this->store->load($session->id())->exists());
    }
}
