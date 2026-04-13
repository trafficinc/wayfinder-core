<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Wayfinder\Auth\Authenticate;
use Wayfinder\Auth\AuthManager;
use Wayfinder\Auth\Can;
use Wayfinder\Auth\Gate;
use Wayfinder\Http\Request;
use Wayfinder\Http\Response;
use Wayfinder\Session\Session;
use Wayfinder\Session\SessionManager;
use Wayfinder\Tests\Concerns\MakesRequests;
use Wayfinder\Tests\Concerns\UsesDatabase;

final class AuthMiddlewareTest extends TestCase
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

    private function okNext(): callable
    {
        return static fn (Request $req): Response => Response::text('OK');
    }

    // -------------------------------------------------------------------------
    // Authenticate middleware
    // -------------------------------------------------------------------------

    public function testAuthenticateReturns401ForGuest(): void
    {
        $middleware = new Authenticate($this->auth);
        $response = $middleware->handle($this->makeRequest(), $this->okNext());

        self::assertSame(401, $response->status());
        self::assertStringContainsString('Unauthenticated', $response->content());
    }

    public function testAuthenticatePassesThroughForAuthenticatedUser(): void
    {
        $this->auth->login(1);
        $middleware = new Authenticate($this->auth);

        $response = $middleware->handle($this->makeRequest(), $this->okNext());

        self::assertSame(200, $response->status());
        self::assertSame('OK', $response->content());
    }

    public function testAuthenticateResponseIsJson(): void
    {
        $middleware = new Authenticate($this->auth);
        $response = $middleware->handle($this->makeRequest(), $this->okNext());

        $decoded = json_decode($response->content(), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('message', $decoded);
    }

    public function testAuthenticateCannotBeBypassedWithSpoofedAjaxHeader(): void
    {
        $middleware = new Authenticate($this->auth);
        $response = $middleware->handle(
            $this->makeRequest(headers: ['x-requested-with' => 'XMLHttpRequest']),
            $this->okNext(),
        );

        self::assertSame(401, $response->status());
    }

    public function testAuthenticateCannotBeBypassedWithJsonAcceptHeader(): void
    {
        $middleware = new Authenticate($this->auth);
        $response = $middleware->handle(
            $this->makeRequest(headers: ['accept' => 'application/json']),
            $this->okNext(),
        );

        self::assertSame(401, $response->status());
    }

    // -------------------------------------------------------------------------
    // Can middleware
    // -------------------------------------------------------------------------

    public function testCanReturns403WhenAbilityNotDefined(): void
    {
        $middleware = new Can($this->gate);
        $response = $middleware->handle($this->makeRequest(), $this->okNext(), 'undefined.ability');

        self::assertSame(403, $response->status());
    }

    public function testCanReturns403WhenAbilityDenies(): void
    {
        $this->gate->define('admin', static fn (?array $user, Request $req): bool => false);
        $middleware = new Can($this->gate);

        $response = $middleware->handle($this->makeRequest(), $this->okNext(), 'admin');

        self::assertSame(403, $response->status());
    }

    public function testCanPassesThroughWhenAbilityAllows(): void
    {
        $this->gate->define('public', static fn (?array $user, Request $req): bool => true);
        $middleware = new Can($this->gate);

        $response = $middleware->handle($this->makeRequest(), $this->okNext(), 'public');

        self::assertSame(200, $response->status());
    }

    public function testCanReturns403WithEmptyAbility(): void
    {
        $middleware = new Can($this->gate);
        $response = $middleware->handle($this->makeRequest(), $this->okNext(), '');

        self::assertSame(403, $response->status());
    }

    public function testAdminAbilityGrantedToAdminUser(): void
    {
        $this->db->statement("INSERT INTO users (email, is_admin) VALUES ('admin@example.com', 1)");
        $id = (int) $this->db->lastInsertId();
        $this->auth->login($id);

        $this->gate->define('admin.panel', static fn (?array $user, Request $req): bool => (bool) ($user['is_admin'] ?? false));
        $middleware = new Can($this->gate);

        $response = $middleware->handle($this->makeRequest(), $this->okNext(), 'admin.panel');

        self::assertSame(200, $response->status());
    }

    public function testAdminAbilityDeniedToRegularUser(): void
    {
        $this->db->statement("INSERT INTO users (email, is_admin) VALUES ('user@example.com', 0)");
        $id = (int) $this->db->lastInsertId();
        $this->auth->login($id);

        $this->gate->define('admin.panel', static fn (?array $user, Request $req): bool => (bool) ($user['is_admin'] ?? false));
        $middleware = new Can($this->gate);

        $response = $middleware->handle($this->makeRequest(), $this->okNext(), 'admin.panel');

        self::assertSame(403, $response->status());
    }

    public function testAdminAbilityDeniedToGuest(): void
    {
        $this->gate->define('admin.panel', static fn (?array $user, Request $req): bool => $user !== null && (bool) ($user['is_admin'] ?? false));
        $middleware = new Can($this->gate);

        $response = $middleware->handle($this->makeRequest(), $this->okNext(), 'admin.panel');

        self::assertSame(403, $response->status());
    }
}
