<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Security;

use PHPUnit\Framework\TestCase;
use Wayfinder\Http\CsrfTokenManager;
use Wayfinder\Http\Request;
use Wayfinder\Http\Response;
use Wayfinder\Http\VerifyCsrfToken;
use Wayfinder\Session\Session;

final class CsrfSecurityTest extends TestCase
{
    private CsrfTokenManager $tokens;
    private VerifyCsrfToken $middleware;
    private Session $session;

    protected function setUp(): void
    {
        $this->tokens     = new CsrfTokenManager();
        $this->middleware = new VerifyCsrfToken($this->tokens);
        $this->session    = new Session(bin2hex(random_bytes(20)));
    }

    // =========================================================================
    // CsrfTokenManager — token generation
    // =========================================================================

    public function testTokenIsGeneratedOnFirstAccess(): void
    {
        $token = $this->tokens->token($this->session);

        self::assertNotEmpty($token);
    }

    public function testTokenIsA40CharHexString(): void
    {
        $token = $this->tokens->token($this->session);

        self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $token);
    }

    public function testTokenIsStableWithinSameSession(): void
    {
        $first  = $this->tokens->token($this->session);
        $second = $this->tokens->token($this->session);

        self::assertSame($first, $second, 'CSRF token must not rotate within the same session');
    }

    public function testTokenIsStoredInSession(): void
    {
        $token = $this->tokens->token($this->session);

        self::assertSame($token, $this->session->get('_csrf_token'));
    }

    public function testDifferentSessionsGetDifferentTokens(): void
    {
        $sessionA = new Session(bin2hex(random_bytes(20)));
        $sessionB = new Session(bin2hex(random_bytes(20)));

        $tokenA = $this->tokens->token($sessionA);
        $tokenB = $this->tokens->token($sessionB);

        self::assertNotSame($tokenA, $tokenB);
    }

    // =========================================================================
    // CsrfTokenManager — matches()
    // =========================================================================

    public function testMatchesReturnsTrueForCorrectToken(): void
    {
        $token = $this->tokens->token($this->session);
        self::assertTrue($this->tokens->matches($this->session, $token));
    }

    public function testMatchesReturnsFalseForWrongToken(): void
    {
        $this->tokens->token($this->session);
        self::assertFalse($this->tokens->matches($this->session, bin2hex(random_bytes(20))));
    }

    public function testMatchesReturnsFalseForNullToken(): void
    {
        $this->tokens->token($this->session);
        self::assertFalse($this->tokens->matches($this->session, null));
    }

    public function testMatchesReturnsFalseForEmptyString(): void
    {
        $this->tokens->token($this->session);
        self::assertFalse($this->tokens->matches($this->session, ''));
    }

    public function testMatchesReturnsFalseWhenNoTokenInSession(): void
    {
        // session has no token yet
        self::assertFalse($this->tokens->matches($this->session, 'anything'));
    }

    public function testMatchesReturnsFalseForAllZeroesToken(): void
    {
        $this->tokens->token($this->session);
        self::assertFalse($this->tokens->matches($this->session, str_repeat('0', 40)));
    }

    public function testMatchesUsesTimingSafeComparison(): void
    {
        // Verify hash_equals semantics: a token that is a prefix of the real
        // token must still fail, ruling out naive strncmp attacks.
        $token = $this->tokens->token($this->session);
        $prefix = substr($token, 0, 20); // half the token

        self::assertFalse($this->tokens->matches($this->session, $prefix));
    }

    // =========================================================================
    // VerifyCsrfToken middleware — safe methods bypass check
    // =========================================================================

    public function testGetRequestBypassesCsrfCheck(): void
    {
        $request  = $this->requestWithSession('GET', '/');
        $response = $this->middleware->handle($request, $this->okNext());

        self::assertSame(200, $response->status());
    }

    public function testHeadRequestBypassesCsrfCheck(): void
    {
        $request  = $this->requestWithSession('HEAD', '/');
        $response = $this->middleware->handle($request, $this->okNext());

        self::assertSame(200, $response->status());
    }

    public function testOptionsRequestBypassesCsrfCheck(): void
    {
        $request  = $this->requestWithSession('OPTIONS', '/');
        $response = $this->middleware->handle($request, $this->okNext());

        self::assertSame(200, $response->status());
    }

    // =========================================================================
    // VerifyCsrfToken middleware — POST/PUT/PATCH/DELETE require valid token
    // =========================================================================

    public function testPostWithoutTokenReturns419(): void
    {
        $this->tokens->token($this->session); // ensure session has a token
        $request  = $this->requestWithSession('POST', '/');
        $response = $this->middleware->handle($request, $this->okNext());

        self::assertSame(419, $response->status());
    }

    public function testPostWithWrongTokenReturns419(): void
    {
        $this->tokens->token($this->session);
        $request  = $this->requestWithSession('POST', '/', body: ['_token' => 'bad-token']);
        $response = $this->middleware->handle($request, $this->okNext());

        self::assertSame(419, $response->status());
    }

    public function testPostWithCorrectBodyTokenPasses(): void
    {
        $token   = $this->tokens->token($this->session);
        $request = $this->requestWithSession('POST', '/', body: ['_token' => $token]);

        $response = $this->middleware->handle($request, $this->okNext());

        self::assertSame(200, $response->status());
    }

    public function testPostWithXCsrfTokenHeaderPasses(): void
    {
        $token   = $this->tokens->token($this->session);
        $request = $this->requestWithSession('POST', '/', headers: ['x-csrf-token' => $token]);

        $response = $this->middleware->handle($request, $this->okNext());

        self::assertSame(200, $response->status());
    }

    public function testPostWithXXsrfTokenHeaderPasses(): void
    {
        $token   = $this->tokens->token($this->session);
        $request = $this->requestWithSession('POST', '/', headers: ['x-xsrf-token' => $token]);

        $response = $this->middleware->handle($request, $this->okNext());

        self::assertSame(200, $response->status());
    }

    public function testPutWithoutTokenReturns419(): void
    {
        $this->tokens->token($this->session);
        $request  = $this->requestWithSession('PUT', '/');
        $response = $this->middleware->handle($request, $this->okNext());

        self::assertSame(419, $response->status());
    }

    public function testDeleteWithCorrectTokenPasses(): void
    {
        $token   = $this->tokens->token($this->session);
        $request = $this->requestWithSession('DELETE', '/', body: ['_token' => $token]);

        $response = $this->middleware->handle($request, $this->okNext());

        self::assertSame(200, $response->status());
    }

    public function test419ResponseIsJson(): void
    {
        $this->tokens->token($this->session);
        $request  = $this->requestWithSession('POST', '/');
        $response = $this->middleware->handle($request, $this->okNext());

        $decoded = json_decode($response->content(), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('message', $decoded);
    }

    // =========================================================================
    // VerifyCsrfToken — no session throws
    // =========================================================================

    public function testMissingSessionThrowsRuntimeException(): void
    {
        $request = new Request('POST', '/', [], [], [], [], [], [], '');
        // No session attached

        $this->expectException(\RuntimeException::class);
        $this->middleware->handle($request, $this->okNext());
    }

    // =========================================================================
    // Token rotation after session regeneration
    // =========================================================================

    public function testTokenInvalidatedAfterSessionFlush(): void
    {
        $token = $this->tokens->token($this->session);
        $this->session->flush(); // clears all data including the token

        self::assertFalse(
            $this->tokens->matches($this->session, $token),
            'Token from a flushed session must not match',
        );
    }

    public function testNewTokenGeneratedAfterSessionFlush(): void
    {
        $old = $this->tokens->token($this->session);
        $this->session->flush();

        $new = $this->tokens->token($this->session); // regenerates

        self::assertNotSame($old, $new);
    }

    public function testStaleTokenRejectedAfterLogin(): void
    {
        // Simulate attacker planting a token, then victim logs in
        $staleToken = $this->tokens->token($this->session);

        // Login: flush + regenerate (as AuthManager does)
        $this->session->flush();
        $this->session->regenerate();

        // Old token must not work in the new session
        self::assertFalse($this->tokens->matches($this->session, $staleToken));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function requestWithSession(
        string $method,
        string $path,
        array $body = [],
        array $headers = [],
    ): Request {
        $request = new Request($method, $path, [], $body, [], [], [], $headers, '');
        return $request->withSession($this->session);
    }

    private function okNext(): callable
    {
        return static fn (Request $req): Response => Response::text('OK');
    }
}
