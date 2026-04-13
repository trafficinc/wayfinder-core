<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Session;

use PHPUnit\Framework\TestCase;
use Wayfinder\Http\Request;
use Wayfinder\Http\Response;
use Wayfinder\Http\ValidationException;
use Wayfinder\Session\FileSessionStore;
use Wayfinder\Session\Session;
use Wayfinder\Session\SessionManager;
use Wayfinder\Session\SessionStore;
use Wayfinder\Session\StartSession;
use Wayfinder\Tests\Concerns\MakesRequests;
use Wayfinder\Tests\Concerns\UsesTempDirectory;

final class StartSessionTest extends TestCase
{
    use UsesTempDirectory;
    use MakesRequests;

    private SessionStore $store;
    private SessionManager $sessions;
    private StartSession $middleware;

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
        $this->store = new FileSessionStore($this->tempDir);
        $this->sessions = new SessionManager();
        $this->middleware = new StartSession($this->store, $this->sessions, 'wf_session');
    }

    protected function tearDown(): void
    {
        $this->tearDownTempDirectory();
    }

    private function okNext(): callable
    {
        return static fn (Request $req): Response => Response::text('OK');
    }

    private function captureNext(?Request &$captured): callable
    {
        return function (Request $req) use (&$captured): Response {
            $captured = $req;
            return Response::text('OK');
        };
    }

    // -------------------------------------------------------------------------
    // Session creation
    // -------------------------------------------------------------------------

    public function testCreatesNewSessionWhenNoCookie(): void
    {
        $request = $this->makeRequest();
        $captured = null;

        $this->middleware->handle($request, $this->captureNext($captured));

        self::assertNotNull($captured);
        self::assertTrue($captured->hasSession());
    }

    public function testLoadsSessionFromValidCookie(): void
    {
        $existing = new Session('aabbcc' . str_repeat('0', 34));
        $existing->put('user_id', 7);
        $this->store->save($existing);

        $request = $this->makeRequest(cookies: ['wf_session' => $existing->id()]);
        $captured = null;

        $this->middleware->handle($request, $this->captureNext($captured));

        self::assertNotNull($captured);
        self::assertSame(7, $captured->session()->get('user_id'));
    }

    public function testRejectsInvalidSessionIdCookie(): void
    {
        // Too short, wrong characters
        $request = $this->makeRequest(cookies: ['wf_session' => 'invalid-id']);
        $captured = null;

        $this->middleware->handle($request, $this->captureNext($captured));

        self::assertNotNull($captured);
        // Should get a fresh session, not load from the invalid ID
        self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $captured->session()->id());
        self::assertNotSame('invalid-id', $captured->session()->id());
    }

    public function testRejectsNonHexSessionIdCookie(): void
    {
        $request = $this->makeRequest(cookies: ['wf_session' => str_repeat('z', 40)]);
        $captured = null;

        $this->middleware->handle($request, $this->captureNext($captured));

        self::assertNotNull($captured);
        self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $captured->session()->id());
    }

    // -------------------------------------------------------------------------
    // Cookie handling
    // -------------------------------------------------------------------------

    public function testSetsSessionCookieOnResponse(): void
    {
        $request = $this->makeRequest();

        $response = $this->middleware->handle($request, $this->okNext());

        $cookies = $response->cookies();
        self::assertNotEmpty($cookies);
        self::assertSame('wf_session', $cookies[0]->name());
    }

    public function testDoesNotResetCookieForUnchangedExistingSession(): void
    {
        $existing = new Session('aabbcc' . str_repeat('0', 34), ['key' => 'val'], true);
        $this->store->save($existing);
        // Make a clean unmodified session
        $clean = $this->store->load($existing->id());

        $request = $this->makeRequest(cookies: ['wf_session' => $clean->id()]);

        // Provide a next that does NOT modify the session
        $next = function (Request $req): Response {
            // Read-only — no writes
            $req->session()->get('key');
            return Response::text('OK');
        };

        $response = $this->middleware->handle($request, $next);

        // No new cookie set for a clean, existing, unmodified session
        self::assertEmpty($response->cookies());
    }

    // -------------------------------------------------------------------------
    // Flash data lifecycle
    // -------------------------------------------------------------------------

    public function testAgesFlashDataOnEachRequest(): void
    {
        $existing = new Session('aabbcc' . str_repeat('0', 34));
        $existing->flash('status', 'Saved!');
        $this->store->save($existing);

        // Request 1 — flash should be visible
        $request1 = $this->makeRequest(cookies: ['wf_session' => $existing->id()]);
        $captured1 = null;
        $this->middleware->handle($request1, $this->captureNext($captured1));
        self::assertNotNull($captured1);
        self::assertSame('Saved!', $captured1->session()->get('status'));

        // Request 2 — flash is aged out; save session between requests
        $this->store->save($captured1->session());
        $request2 = $this->makeRequest(cookies: ['wf_session' => $captured1->session()->id()]);
        $captured2 = null;
        $this->middleware->handle($request2, $this->captureNext($captured2));
        self::assertNotNull($captured2);
        self::assertFalse($captured2->session()->has('status'));
    }

    // -------------------------------------------------------------------------
    // ValidationException handling
    // -------------------------------------------------------------------------

    public function testValidationExceptionFlashesErrorsAndRedirects(): void
    {
        $request = $this->makeRequest('POST', '/form',
            data: ['name' => ''],
            headers: ['referer' => '/form'],
        );

        $next = function (Request $req): Response {
            throw new ValidationException(['name' => ['Required.']]);
        };

        $response = $this->middleware->handle($request, $next);

        self::assertSame(302, $response->status());
    }

    public function testValidationRedirectRejectsExternalReferer(): void
    {
        $request = $this->makeRequest('POST', '/form',
            data: ['name' => ''],
            headers: ['referer' => 'https://evil.example/phish'],
        );

        $next = function (Request $req): Response {
            throw new ValidationException(['name' => ['Required.']]);
        };

        $response = $this->middleware->handle($request, $next);

        self::assertSame('/form', $response->headers()['Location'] ?? null);
    }

    public function testValidationRedirectRejectsSchemeRelativeReferer(): void
    {
        $request = $this->makeRequest('POST', '/form',
            data: ['name' => ''],
            headers: ['referer' => '//evil.example/phish'],
        );

        $next = function (Request $req): Response {
            throw new ValidationException(['name' => ['Required.']]);
        };

        $response = $this->middleware->handle($request, $next);

        self::assertSame('/form', $response->headers()['Location'] ?? null);
    }

    public function testValidationRedirectUsesExplicitRedirectTargetBeforeReferer(): void
    {
        $request = $this->makeRequest('POST', '/contact',
            data: ['name' => '', '_redirect' => '/'],
            headers: ['referer' => '/contact'],
        );

        $next = function (Request $req): Response {
            throw new ValidationException(['name' => ['Required.']]);
        };

        $response = $this->middleware->handle($request, $next);

        self::assertSame('/', $response->headers()['Location'] ?? null);
    }

    public function testValidationExceptionRethrowsForJsonRequest(): void
    {
        $request = $this->jsonRequest('POST', '/api/form', ['name' => '']);

        $next = function (Request $req): Response {
            throw new ValidationException(['name' => ['Required.']]);
        };

        $this->expectException(ValidationException::class);
        $this->middleware->handle($request, $next);
    }

    public function testValidationExceptionFlashesErrorsIntoSession(): void
    {
        $request = $this->makeRequest('POST', '/form', data: ['email' => 'bad']);

        $captured = null;
        $next = function (Request $req) use (&$captured): Response {
            $captured = $req;
            throw new ValidationException(['email' => ['Invalid email.']]);
        };

        $this->middleware->handle($request, $next);

        self::assertNotNull($captured);
        $errors = $captured->session()->get('_errors');
        self::assertIsArray($errors);
        self::assertArrayHasKey('default', $errors);
        self::assertSame(['Invalid email.'], $errors['default']['email']);
    }

    public function testValidationExceptionFlashesOldInput(): void
    {
        $request = $this->makeRequest('POST', '/form', data: ['email' => 'bad@', '_token' => 'csrf']);

        $captured = null;
        $next = function (Request $req) use (&$captured): Response {
            $captured = $req;
            throw new ValidationException(['email' => ['Invalid.']]);
        };

        $this->middleware->handle($request, $next);

        self::assertNotNull($captured);
        $old = $captured->session()->get('_old_input');
        self::assertIsArray($old);
        // _token should be stripped from old input
        self::assertArrayNotHasKey('_token', $old['default'] ?? []);
        self::assertSame('bad@', $old['default']['email'] ?? null);
    }

    public function testNamedBagErrorsAccumulateAcrossMultipleForms(): void
    {
        $request = $this->makeRequest('POST', '/page', data: ['x' => '']);

        $loginErrors = ['email' => ['Required.']];
        $registerErrors = ['name' => ['Required.']];

        $captured = null;
        $first = function (Request $req) use (&$captured, $loginErrors): Response {
            $captured = $req;
            throw new ValidationException($loginErrors, 'Invalid', null, 'login');
        };
        $this->middleware->handle($request, $first);
        self::assertNotNull($captured);
        $this->store->save($captured->session());
        $savedId = $captured->session()->id();

        // Second request fires the second form validation
        $request2 = $this->makeRequest('POST', '/page',
            data: ['y' => ''],
            cookies: ['wf_session' => $savedId],
        );
        $captured2 = null;
        $second = function (Request $req) use (&$captured2, $registerErrors): Response {
            $captured2 = $req;
            throw new ValidationException($registerErrors, 'Invalid', null, 'register');
        };
        $this->middleware->handle($request2, $second);

        self::assertNotNull($captured2);
        $errors = $captured2->session()->get('_errors');
        self::assertArrayHasKey('login', $errors ?? []);
        self::assertArrayHasKey('register', $errors ?? []);
    }
}
