<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Wayfinder\Auth\AuthManager;
use Wayfinder\Auth\Gate;
use Wayfinder\Http\Request;
use Wayfinder\Session\Session;
use Wayfinder\Session\SessionManager;
use Wayfinder\Tests\Concerns\MakesRequests;
use Wayfinder\Tests\Concerns\UsesDatabase;

final class GateTest extends TestCase
{
    use MakesRequests;
    use UsesDatabase;

    private SessionManager $sessions;
    private Session $session;
    private AuthManager $auth;
    private Gate $gate;

    protected function setUp(): void
    {
        $this->setUpDatabase();

        $this->session = new Session(bin2hex(random_bytes(20)));
        $this->sessions = new SessionManager();
        $this->sessions->setCurrent($this->session);
        $this->auth = new AuthManager($this->sessions, 'auth.user_id', 'users', 'id');
        $this->gate = new Gate($this->auth);
    }

    protected function tearDown(): void
    {
        $this->sessions->clearCurrent();
        $this->tearDownDatabase();
    }

    // -------------------------------------------------------------------------
    // Undefined ability
    // -------------------------------------------------------------------------

    public function testUndefinedAbilityReturnsFalseForAllows(): void
    {
        self::assertFalse($this->gate->allows('undefined', $this->makeRequest()));
    }

    public function testUndefinedAbilityReturnsTrueForDenies(): void
    {
        self::assertTrue($this->gate->denies('undefined', $this->makeRequest()));
    }

    // -------------------------------------------------------------------------
    // define + allows / denies
    // -------------------------------------------------------------------------

    public function testDefinedAbilityThatReturnsTrue(): void
    {
        $this->gate->define('open', static fn (?array $user, Request $req): bool => true);

        self::assertTrue($this->gate->allows('open', $this->makeRequest()));
        self::assertFalse($this->gate->denies('open', $this->makeRequest()));
    }

    public function testDefinedAbilityThatReturnsFalse(): void
    {
        $this->gate->define('closed', static fn (?array $user, Request $req): bool => false);

        self::assertFalse($this->gate->allows('closed', $this->makeRequest()));
        self::assertTrue($this->gate->denies('closed', $this->makeRequest()));
    }

    // -------------------------------------------------------------------------
    // Guest user (null) passed to callback
    // -------------------------------------------------------------------------

    public function testGuestUserPassedAsNullToCallback(): void
    {
        $receivedUser = 'not-set';
        $this->gate->define('check', static function (?array $user, Request $req) use (&$receivedUser): bool {
            $receivedUser = $user;
            return true;
        });

        $this->gate->allows('check', $this->makeRequest());

        self::assertNull($receivedUser);
    }

    // -------------------------------------------------------------------------
    // Authenticated user passed to callback
    // -------------------------------------------------------------------------

    public function testAuthenticatedUserPassedToCallback(): void
    {
        $this->db->statement("INSERT INTO users (email) VALUES ('alice@example.com')");
        $id = (int) $this->db->lastInsertId();
        $this->auth->login($id);

        $receivedUser = 'not-set';
        $this->gate->define('check', static function (?array $user, Request $req) use (&$receivedUser): bool {
            $receivedUser = $user;
            return true;
        });

        $this->gate->allows('check', $this->makeRequest());

        self::assertIsArray($receivedUser);
        self::assertSame('alice@example.com', $receivedUser['email']);
    }

    // -------------------------------------------------------------------------
    // Ability based on user attributes
    // -------------------------------------------------------------------------

    public function testAbilityBasedOnUserAttribute(): void
    {
        $this->db->statement("INSERT INTO users (email, is_admin) VALUES ('admin@example.com', 1)");
        $id = (int) $this->db->lastInsertId();
        $this->auth->login($id);

        $this->gate->define('admin', static fn (?array $user, Request $req): bool => (bool) ($user['is_admin'] ?? false));

        self::assertTrue($this->gate->allows('admin', $this->makeRequest()));
    }

    public function testAbilityDeniedForNonAdminUser(): void
    {
        $this->db->statement("INSERT INTO users (email, is_admin) VALUES ('user@example.com', 0)");
        $id = (int) $this->db->lastInsertId();
        $this->auth->login($id);

        $this->gate->define('admin', static fn (?array $user, Request $req): bool => (bool) ($user['is_admin'] ?? false));

        self::assertFalse($this->gate->allows('admin', $this->makeRequest()));
    }

    // -------------------------------------------------------------------------
    // define returns self (fluent)
    // -------------------------------------------------------------------------

    public function testDefineReturnsSelf(): void
    {
        $result = $this->gate->define('a', static fn (): bool => true);

        self::assertSame($this->gate, $result);
    }

    // -------------------------------------------------------------------------
    // Ability with extra arguments
    // -------------------------------------------------------------------------

    public function testAbilityReceivesExtraArguments(): void
    {
        $received = [];
        $this->gate->define('resource', static function (?array $user, Request $req, mixed ...$args) use (&$received): bool {
            $received = $args;
            return true;
        });

        $this->gate->allows('resource', $this->makeRequest(), 'post', 42);

        self::assertSame(['post', 42], $received);
    }

    // -------------------------------------------------------------------------
    // Overwriting an ability
    // -------------------------------------------------------------------------

    public function testLaterDefineOverwritesEarlier(): void
    {
        $this->gate->define('flip', static fn (): bool => false);
        $this->gate->define('flip', static fn (): bool => true);

        self::assertTrue($this->gate->allows('flip', $this->makeRequest()));
    }
}
