<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Http;

use PHPUnit\Framework\TestCase;
use Wayfinder\Http\CsrfTokenManager;
use Wayfinder\Http\Request;
use Wayfinder\Http\Response;
use Wayfinder\Http\VerifyCsrfToken;
use Wayfinder\Session\Session;
use Wayfinder\Tests\Concerns\MakesRequests;

final class VerifyCsrfTokenTest extends TestCase
{
    use MakesRequests;

    private CsrfTokenManager $tokens;
    private VerifyCsrfToken $middleware;
    private Session $session;

    protected function setUp(): void
    {
        $this->tokens = new CsrfTokenManager();
        $this->middleware = new VerifyCsrfToken($this->tokens);
        $this->session = new Session(bin2hex(random_bytes(20)));
    }

    private function withSession(Request $request): Request
    {
        return $request->withSession($this->session);
    }

    private function okNext(): callable
    {
        return static fn (): Response => Response::text('OK');
    }

    public function testSafeMethodsBypassCsrfCheck(): void
    {
        $response = $this->middleware->handle(
            $this->withSession($this->makeRequest('GET', '/form')),
            $this->okNext(),
        );

        self::assertSame(200, $response->status());
    }

    public function testRejectsPostWithoutToken(): void
    {
        $response = $this->middleware->handle(
            $this->withSession($this->makeRequest('POST', '/submit')),
            $this->okNext(),
        );

        self::assertSame(419, $response->status());
        self::assertStringContainsString('CSRF token mismatch', $response->content());
    }

    public function testRejectsForgedHeaderToken(): void
    {
        $request = $this->withSession($this->makeRequest(
            'POST',
            '/submit',
            headers: ['x-csrf-token' => 'forged-token'],
        ));

        $response = $this->middleware->handle($request, $this->okNext());

        self::assertSame(419, $response->status());
    }

    public function testRejectsForgedXsrfHeaderToken(): void
    {
        $request = $this->withSession($this->makeRequest(
            'POST',
            '/submit',
            headers: ['x-xsrf-token' => 'forged-token'],
        ));

        $response = $this->middleware->handle($request, $this->okNext());

        self::assertSame(419, $response->status());
    }

    public function testAllowsMatchingTokenFromHeader(): void
    {
        $token = $this->tokens->token($this->session);
        $request = $this->withSession($this->makeRequest(
            'POST',
            '/submit',
            headers: ['x-csrf-token' => $token],
        ));

        $response = $this->middleware->handle($request, $this->okNext());

        self::assertSame(200, $response->status());
    }
}
