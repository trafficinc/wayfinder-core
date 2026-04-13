<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Security;

use PHPUnit\Framework\TestCase;
use Wayfinder\Auth\AuthManager;
use Wayfinder\Session\FileSessionStore;
use Wayfinder\Session\Session;
use Wayfinder\Session\SessionManager;
use Wayfinder\Tests\Concerns\UsesDatabase;

/**
 * Session fixation prevention and logout invalidation tests.
 *
 * All tests use a real FileSessionStore writing to a temp SQLite-backed
 * session directory so disk-level file cleanup can be verified.
 */
final class SessionFixationTest extends TestCase
{
    use UsesDatabase;

    private string $sessionDir;
    private FileSessionStore $store;
    private SessionManager $sessions;
    private AuthManager $auth;

    protected function setUp(): void
    {
        $this->setUpDatabase();

        $this->sessionDir = sys_get_temp_dir() . '/wf_fix_test_' . uniqid('', true);
        mkdir($this->sessionDir, 0777, true);

        $this->store    = new FileSessionStore($this->sessionDir);
        $this->sessions = new SessionManager();
        $this->auth     = new AuthManager($this->sessions, 'auth.user_id', 'users', 'id');
    }

    protected function tearDown(): void
    {
        $this->sessions->clearCurrent();
        $this->tearDownDatabase();
        $this->removeDirectory($this->sessionDir);
    }

    // =========================================================================
    // Session fixation — login
    // =========================================================================

    public function testLoginRegeneratesSessionId(): void
    {
        $session = $this->startSession();
        $before  = $session->id();

        $this->auth->login(1);

        self::assertNotSame($before, $session->id(), 'Login must rotate the session ID');
    }

    public function testLoginDeletesOldSessionFileFromDisk(): void
    {
        $session = $this->startExistingSession(['role' => 'guest']);
        $oldId   = $session->id();
        $this->assertSessionFileExists($oldId);

        $this->auth->login(1);
        $this->store->save($session);

        $this->assertSessionFileNotExists($oldId, 'Old session file must be deleted after login');
    }

    public function testLoginCreatesNewSessionFileOnDisk(): void
    {
        $session = $this->startExistingSession();
        $this->auth->login(1);
        $this->store->save($session);

        $this->assertSessionFileExists($session->id(), 'New session file must exist after login');
    }

    public function testLoginPreservesUserDataAcrossIdRotation(): void
    {
        $session = $this->startSession();
        $session->put('cart', ['item_1', 'item_2']);

        $this->auth->login(1);

        self::assertSame(['item_1', 'item_2'], $session->get('cart'), 'Session data must survive ID rotation');
    }

    public function testLoginWritesUserIdToNewSession(): void
    {
        $session = $this->startSession();
        $this->auth->login(42);

        self::assertSame(42, $session->get('auth.user_id'));
    }

    public function testPreSessionIdCannotBeReuseAfterLogin(): void
    {
        $session = $this->startExistingSession();
        $attackerKnownId = $session->id();

        $this->auth->login(1);
        $this->store->save($session);

        // The attacker tries to load the pre-login session ID — must get a fresh empty session
        $attackerSession = $this->store->load($attackerKnownId);
        self::assertFalse($attackerSession->exists(), 'Pre-login session file must not be loadable after rotation');
        self::assertEmpty($attackerSession->all(), 'Pre-login session must have no data after rotation');
    }

    public function testDoubleLoginProducesTwoDistinctIdRotations(): void
    {
        $session = $this->startSession();
        $id0     = $session->id();

        $this->auth->login(1);
        $id1 = $session->id();

        $this->auth->login(2);
        $id2 = $session->id();

        self::assertNotSame($id0, $id1);
        self::assertNotSame($id1, $id2);
        self::assertNotSame($id0, $id2);
    }

    public function testLoginWithAlreadyAuthenticatedSessionStillRegenerates(): void
    {
        $session = $this->startSession();
        $this->auth->login(1);
        $idAfterFirstLogin = $session->id();

        // Second login (e.g. switching accounts)
        $this->auth->login(2);

        self::assertNotSame($idAfterFirstLogin, $session->id());
        self::assertSame(2, $session->get('auth.user_id'));
    }

    // =========================================================================
    // Session fixation — regenerate() semantics
    // =========================================================================

    public function testRegenerateWithDestroyTrueSetsPreviousId(): void
    {
        $session = new Session(bin2hex(random_bytes(20)));
        $oldId   = $session->id();

        $session->regenerate(destroy: true);

        self::assertSame($oldId, $session->previousId());
    }

    public function testRegenerateWithDestroyFalseDoesNotSetPreviousId(): void
    {
        $session = new Session(bin2hex(random_bytes(20)));

        $session->regenerate(destroy: false);

        self::assertNull($session->previousId());
    }

    public function testSyncAfterSaveClearsPreviousId(): void
    {
        $session = new Session(bin2hex(random_bytes(20)));
        $session->regenerate(destroy: true);
        self::assertNotNull($session->previousId());

        $session->syncAfterSave();

        self::assertNull($session->previousId());
    }

    public function testStoreDeletesOldFileWhenPreviousIdIsSet(): void
    {
        $session = $this->startExistingSession(['key' => 'value']);
        $oldId   = $session->id();
        $this->assertSessionFileExists($oldId);

        $session->regenerate(destroy: true);
        $this->store->save($session);

        $this->assertSessionFileNotExists($oldId);
        $this->assertSessionFileExists($session->id());
    }

    public function testRegenerateWithoutDestroyDoesNotDeleteOldFile(): void
    {
        $session = $this->startExistingSession(['key' => 'value']);
        $oldId   = $session->id();
        $this->assertSessionFileExists($oldId);

        $session->regenerate(destroy: false);
        $this->store->save($session);

        // previousId is null so store doesn't delete anything
        $this->assertSessionFileExists($oldId, 'Old file must survive a no-destroy regenerate');
    }

    // =========================================================================
    // Logout invalidation
    // =========================================================================

    public function testLogoutRotatesSessionId(): void
    {
        $session = $this->startSession();
        $this->auth->login(1);
        $idAfterLogin = $session->id();

        $this->auth->logout();

        self::assertNotSame($idAfterLogin, $session->id(), 'Logout must rotate the session ID');
    }

    public function testLogoutDeletesOldSessionFileFromDisk(): void
    {
        $session = $this->startExistingSession();
        $this->auth->login(1);
        $this->store->save($session);

        $idAfterLogin = $session->id();
        $this->assertSessionFileExists($idAfterLogin);

        $this->auth->logout();
        $this->store->save($session);

        $this->assertSessionFileNotExists($idAfterLogin, 'Logged-in session file must be deleted after logout');
    }

    public function testLogoutClearsAllSessionData(): void
    {
        $session = $this->startSession();
        $session->put('cart',   ['a', 'b']);
        $session->put('prefs',  ['theme' => 'dark']);
        $this->auth->login(1);

        $this->auth->logout();

        self::assertEmpty($session->all(), 'Logout must flush all session data');
    }

    public function testLogoutRemovesAuthKey(): void
    {
        $session = $this->startSession();
        $this->auth->login(1);
        self::assertNotNull($session->get('auth.user_id'));

        $this->auth->logout();

        self::assertNull($session->get('auth.user_id'));
    }

    public function testCheckReturnsFalseAfterLogout(): void
    {
        $session = $this->startSession();
        $this->auth->login(1);
        self::assertTrue($this->auth->check());

        $this->auth->logout();

        self::assertFalse($this->auth->check());
    }

    public function testGuestReturnsTrueAfterLogout(): void
    {
        $session = $this->startSession();
        $this->auth->login(1);
        $this->auth->logout();

        self::assertTrue($this->auth->guest());
    }

    public function testIdReturnsNullAfterLogout(): void
    {
        $session = $this->startSession();
        $this->auth->login(1);
        $this->auth->logout();

        self::assertNull($this->auth->id());
    }

    public function testLogoutWithoutLoginIsSafe(): void
    {
        $session = $this->startSession();
        // Should not throw even though user was never logged in
        $this->auth->logout();

        self::assertTrue($this->auth->guest());
    }

    public function testLoginLogoutLoginProducesThreeDistinctSessionIds(): void
    {
        $session = $this->startSession();
        $id0     = $session->id();

        $this->auth->login(1);
        $id1 = $session->id();

        $this->auth->logout();
        $id2 = $session->id();

        $this->auth->login(1);
        $id3 = $session->id();

        $ids = [$id0, $id1, $id2, $id3];
        self::assertSame(4, count(array_unique($ids)), 'Every auth transition must produce a unique session ID');
    }

    public function testLogoutSessionFileIsNotReuseableByAttacker(): void
    {
        $session = $this->startExistingSession();
        $this->auth->login(1);
        $this->store->save($session);

        $attackerKnownId = $session->id(); // attacker observes post-login session

        $this->auth->logout();
        $this->store->save($session);

        // Attacker tries to resume the post-login session after logout
        $staleSession = $this->store->load($attackerKnownId);
        self::assertFalse($staleSession->exists());
        self::assertEmpty($staleSession->all());
    }

    // =========================================================================
    // Malformed / hostile session cookie values
    // =========================================================================

    public function testSessionIdMustBe40HexCharacters(): void
    {
        // StartSession validates format with preg_match; here we test the
        // underlying store directly: a non-hex-40 ID just gets an empty session
        $storeResult = $this->store->load('../../etc/passwd');
        self::assertEmpty($storeResult->all());
    }

    public function testExtremelyLongSessionIdDoesNotLoad(): void
    {
        $longId = str_repeat('a', 10000);
        // Store does not validate — it just won't find a file
        $storeResult = $this->store->load($longId);
        self::assertEmpty($storeResult->all());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function startSession(array $data = []): Session
    {
        $session = new Session(bin2hex(random_bytes(20)));
        foreach ($data as $k => $v) {
            $session->put($k, $v);
        }
        $this->sessions->setCurrent($session);
        return $session;
    }

    private function startExistingSession(array $data = []): Session
    {
        $session = new Session(bin2hex(random_bytes(20)));
        foreach ($data as $k => $v) {
            $session->put($k, $v);
        }
        $this->store->save($session); // writes file; syncAfterSave marks it existing
        $this->sessions->setCurrent($session);
        return $session;
    }

    private function assertSessionFileExists(string $id, string $message = ''): void
    {
        $file = $this->sessionDir . '/' . $id . '.json';
        self::assertFileExists($file, $message ?: "Session file for ID [{$id}] must exist");
    }

    private function assertSessionFileNotExists(string $id, string $message = ''): void
    {
        $file = $this->sessionDir . '/' . $id . '.json';
        self::assertFileDoesNotExist($file, $message ?: "Session file for ID [{$id}] must have been deleted");
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
