<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Wayfinder\Auth\AuthManager;
use Wayfinder\Session\Session;
use Wayfinder\Session\SessionManager;
use Wayfinder\Tests\Concerns\UsesDatabase;

final class AuthManagerTest extends TestCase
{
    use UsesDatabase;

    private SessionManager $sessions;
    private Session $session;
    private AuthManager $auth;

    protected function setUp(): void
    {
        $this->setUpDatabase();

        $this->session = new Session(bin2hex(random_bytes(20)));
        $this->sessions = new SessionManager();
        $this->sessions->setCurrent($this->session);

        $this->auth = new AuthManager($this->sessions, 'auth.user_id', 'users', 'id');
    }

    protected function tearDown(): void
    {
        $this->sessions->clearCurrent();
        $this->tearDownDatabase();
    }

    // -------------------------------------------------------------------------
    // Guest / check
    // -------------------------------------------------------------------------

    public function testGuestReturnsTrueWhenNotLoggedIn(): void
    {
        self::assertTrue($this->auth->guest());
    }

    public function testCheckReturnsFalseWhenNotLoggedIn(): void
    {
        self::assertFalse($this->auth->check());
    }

    public function testIdReturnsNullWhenNotLoggedIn(): void
    {
        self::assertNull($this->auth->id());
    }

    public function testUserReturnsNullWhenNotLoggedIn(): void
    {
        self::assertNull($this->auth->user());
    }

    // -------------------------------------------------------------------------
    // Login
    // -------------------------------------------------------------------------

    public function testLoginSetsUserIdInSession(): void
    {
        $this->auth->login(42);

        self::assertSame(42, $this->auth->id());
        self::assertTrue($this->auth->check());
        self::assertFalse($this->auth->guest());
    }

    public function testLoginRegeneratesSessionToPreventFixation(): void
    {
        $originalId = $this->session->id();

        $this->auth->login(1);

        self::assertNotSame($originalId, $this->session->id());
    }

    public function testLoginPreservesUserIdAfterRegenerate(): void
    {
        $this->auth->login(7);

        self::assertSame(7, $this->auth->id());
    }

    // -------------------------------------------------------------------------
    // User retrieval
    // -------------------------------------------------------------------------

    public function testUserReturnsUserRowWithoutPassword(): void
    {
        $this->db->statement("INSERT INTO users (email, password) VALUES ('ron@example.com', 'hashed-pw')");
        $id = (int) $this->db->lastInsertId();

        $this->auth->login($id);
        $user = $this->auth->user();

        self::assertIsArray($user);
        self::assertSame('ron@example.com', $user['email']);
        self::assertArrayNotHasKey('password', $user);
    }

    public function testUserReturnsNullForUnknownId(): void
    {
        $this->auth->login(99999);

        self::assertNull($this->auth->user());
    }

    // -------------------------------------------------------------------------
    // Logout
    // -------------------------------------------------------------------------

    public function testLogoutFlushesSession(): void
    {
        $this->auth->login(1);
        $this->auth->logout();

        self::assertNull($this->auth->id());
        self::assertTrue($this->auth->guest());
    }

    public function testLogoutRegeneratesSessionToPreventFixation(): void
    {
        $this->auth->login(1);
        $idAfterLogin = $this->session->id();

        $this->auth->logout();

        self::assertNotSame($idAfterLogin, $this->session->id());
    }

    public function testLoginThenLogoutResultsInGuest(): void
    {
        $this->auth->login(1);
        self::assertTrue($this->auth->check());

        $this->auth->logout();
        self::assertFalse($this->auth->check());
    }

    // -------------------------------------------------------------------------
    // Session fixation resistance
    // -------------------------------------------------------------------------

    public function testLoginAndLogoutBothRegenerateSessionId(): void
    {
        $original = $this->session->id();

        $this->auth->login(1);
        $afterLogin = $this->session->id();

        $this->auth->logout();
        $afterLogout = $this->session->id();

        self::assertNotSame($original, $afterLogin);
        self::assertNotSame($afterLogin, $afterLogout);
        self::assertNotSame($original, $afterLogout);
    }

    // -------------------------------------------------------------------------
    // Malformed session state
    // -------------------------------------------------------------------------

    public function testUserHandlesNonIntegerId(): void
    {
        // String ID stored in session (e.g. from a corrupt session)
        $this->session->put('auth.user_id', 'not-a-valid-id');

        $user = $this->auth->user();

        self::assertNull($user);
    }
}
