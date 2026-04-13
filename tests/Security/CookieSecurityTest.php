<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Security;

use PHPUnit\Framework\TestCase;
use Wayfinder\Http\Cookie;
use Wayfinder\Http\Request;
use Wayfinder\Http\Response;
use Wayfinder\Session\FileSessionStore;
use Wayfinder\Session\SessionManager;
use Wayfinder\Session\StartSession;
use Wayfinder\Tests\Concerns\UsesTempDirectory;

final class CookieSecurityTest extends TestCase
{
    use UsesTempDirectory;

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTempDirectory();
    }

    // =========================================================================
    // Cookie::make() — flag defaults
    // =========================================================================

    public function testCookieDefaultsAreSecureByConvention(): void
    {
        $cookie = Cookie::make('sess', 'abc123');
        $opts   = $cookie->options();

        self::assertTrue($opts['httponly'],         'httpOnly must default to true');
        self::assertSame('Lax', $opts['samesite'],  'sameSite must default to Lax');
        self::assertSame('/', $opts['path'],         'path must default to /');
    }

    public function testCookieDefaultSecureFlagIsFalse(): void
    {
        // Allows testing in non-HTTPS environments; production code should
        // construct with secure: true.
        $cookie = Cookie::make('sess', 'abc123');
        self::assertFalse($cookie->options()['secure']);
    }

    public function testCookieSecureFlagCanBeEnabled(): void
    {
        $cookie = Cookie::make('sess', 'abc123', secure: true);
        self::assertTrue($cookie->options()['secure']);
    }

    public function testCookieHttpOnlyCanBeDisabled(): void
    {
        $cookie = Cookie::make('sess', 'abc123', httpOnly: false);
        self::assertFalse($cookie->options()['httponly']);
    }

    public function testCookieSameSiteStrict(): void
    {
        $cookie = Cookie::make('sess', 'abc123', sameSite: 'Strict');
        self::assertSame('Strict', $cookie->options()['samesite']);
    }

    public function testCookieSameSiteNone(): void
    {
        $cookie = Cookie::make('sess', 'abc123', sameSite: 'None');
        self::assertSame('None', $cookie->options()['samesite']);
    }

    public function testCookieDomainScoping(): void
    {
        $cookie = Cookie::make('sess', 'abc123', domain: 'example.com');
        self::assertSame('example.com', $cookie->options()['domain']);
    }

    public function testCookiePathScoping(): void
    {
        $cookie = Cookie::make('sess', 'abc123', path: '/app');
        self::assertSame('/app', $cookie->options()['path']);
    }

    // =========================================================================
    // Cookie::forget() — expiry in the past
    // =========================================================================

    public function testForgetCookieHasExpiryInPast(): void
    {
        $cookie = Cookie::forget('sess');
        self::assertLessThan(time(), $cookie->expires(), 'forget() cookie must be already expired');
    }

    public function testForgetCookieHasEmptyValue(): void
    {
        $cookie = Cookie::forget('sess');
        self::assertSame('', $cookie->value());
    }

    public function testForgetCookieInheritsSecureFlag(): void
    {
        $cookie = Cookie::forget('sess', secure: true);
        self::assertTrue($cookie->options()['secure']);
    }

    public function testForgetCookieInheritsHttpOnlyFlag(): void
    {
        $cookie = Cookie::forget('sess', httpOnly: true);
        self::assertTrue($cookie->options()['httponly']);
    }

    // =========================================================================
    // StartSession — session cookie flags propagated from constructor
    // =========================================================================

    public function testStartSessionIssuedCookieHasHttpOnlyByDefault(): void
    {
        $cookie = $this->runStartSession(secure: false)->sessionCookie();
        self::assertTrue($cookie->options()['httponly']);
    }

    public function testStartSessionIssuedCookieHasLaxSameSiteByDefault(): void
    {
        $cookie = $this->runStartSession()->sessionCookie();
        self::assertSame('Lax', $cookie->options()['samesite']);
    }

    public function testStartSessionProductionModeSetSecureFlag(): void
    {
        $cookie = $this->runStartSession(secure: true)->sessionCookie();
        self::assertTrue($cookie->options()['secure']);
    }

    public function testStartSessionDevelopmentModeSecureFlagIsFalse(): void
    {
        $cookie = $this->runStartSession(secure: false)->sessionCookie();
        self::assertFalse($cookie->options()['secure']);
    }

    public function testStartSessionStrictSameSitePropagated(): void
    {
        $cookie = $this->runStartSession(sameSite: 'Strict')->sessionCookie();
        self::assertSame('Strict', $cookie->options()['samesite']);
    }

    public function testStartSessionCustomCookieNameUsed(): void
    {
        $result = $this->runStartSession(cookieName: '__Host-sess');
        self::assertSame('__Host-sess', $result->sessionCookie()->name());
    }

    public function testStartSessionCookieLifetimeIsInFuture(): void
    {
        $cookie = $this->runStartSession(lifetime: 3600)->sessionCookie();
        self::assertGreaterThan(time(), $cookie->expires());
    }

    public function testStartSessionCookieLifetimeApproximate(): void
    {
        $before = time();
        $cookie = $this->runStartSession(lifetime: 7200)->sessionCookie();
        $after  = time();

        self::assertGreaterThanOrEqual($before + 7200, $cookie->expires());
        self::assertLessThanOrEqual($after + 7200, $cookie->expires());
    }

    // =========================================================================
    // StartSession — cookie not re-issued for unchanged existing sessions
    // =========================================================================

    public function testNoCookieReissuedForUnchangedExistingSession(): void
    {
        $store    = new FileSessionStore($this->tempDir);
        $sessions = new SessionManager();
        $mw       = $this->makeMiddleware($store, $sessions);

        // First request: creates a new session, issues cookie
        $id = $this->sendNewRequest($mw, $sessions)->sessionId;

        // Second request: loads existing session, makes no changes
        $result = $this->sendExistingRequest($mw, $sessions, $id);

        self::assertEmpty($result->cookies, 'No cookie must be re-issued for an unchanged existing session');
    }

    public function testCookieIsReissuedWhenSessionBecomesNew(): void
    {
        $store    = new FileSessionStore($this->tempDir);
        $sessions = new SessionManager();
        $mw       = $this->makeMiddleware($store, $sessions);

        // A brand-new session ID that has no file on disk must trigger a cookie
        $result = $this->sendExistingRequest($mw, $sessions, bin2hex(random_bytes(20)));

        self::assertNotEmpty($result->cookies);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function runStartSession(
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
        string $cookieName = 'wayfinder_session',
        int $lifetime = 7200,
    ): StartSessionResult {
        $store    = new FileSessionStore($this->tempDir);
        $sessions = new SessionManager();
        $mw       = $this->makeMiddleware($store, $sessions, $cookieName, $lifetime, $secure, $httpOnly, $sameSite);

        return $this->sendNewRequest($mw, $sessions);
    }

    private function makeMiddleware(
        FileSessionStore $store,
        SessionManager $sessions,
        string $cookieName = 'wayfinder_session',
        int $lifetime = 7200,
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
    ): StartSession {
        return new StartSession($store, $sessions, $cookieName, $lifetime, '/', '', $secure, $httpOnly, $sameSite);
    }

    private function sendNewRequest(StartSession $mw, SessionManager $sessions): StartSessionResult
    {
        $request  = new Request('GET', '/', [], [], [], [], [], [], '');
        $response = $mw->handle($request, static fn (Request $r): Response => Response::text('ok'));
        $sessions->clearCurrent();

        $sessionCookie = $this->findSessionCookie($response);
        $sessionId     = $sessionCookie?->value() ?? '';

        return new StartSessionResult($response->cookies(), $sessionId, $sessionCookie);
    }

    private function sendExistingRequest(StartSession $mw, SessionManager $sessions, string $id): StartSessionResult
    {
        $request  = new Request('GET', '/', [], [], ['wayfinder_session' => $id], [], [], [], '');
        $response = $mw->handle($request, static fn (Request $r): Response => Response::text('ok'));
        $sessions->clearCurrent();

        return new StartSessionResult($response->cookies(), $id, $this->findSessionCookie($response));
    }

    private function findSessionCookie(Response $response): ?Cookie
    {
        foreach ($response->cookies() as $cookie) {
            if ($cookie->name() === 'wayfinder_session' || str_contains($cookie->name(), 'sess')) {
                return $cookie;
            }
        }
        return $response->cookies()[0] ?? null;
    }
}

// ---------------------------------------------------------------------------
// Value-object to carry test results without polluting test methods
// ---------------------------------------------------------------------------

final class StartSessionResult
{
    public function __construct(
        /** @var list<Cookie> */
        public readonly array $cookies,
        public readonly string $sessionId,
        private readonly ?Cookie $cookie,
    ) {}

    public function sessionCookie(): Cookie
    {
        if ($this->cookie === null) {
            throw new \RuntimeException('No session cookie was issued in this response.');
        }
        return $this->cookie;
    }
}
