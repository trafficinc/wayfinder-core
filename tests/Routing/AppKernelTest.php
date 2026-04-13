<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Routing;

use PHPUnit\Framework\TestCase;
use Wayfinder\Foundation\AppKernel;
use Wayfinder\Http\Request;
use Wayfinder\Http\Response;
use Wayfinder\Http\ValidationException;
use Wayfinder\Routing\Router;
use Wayfinder\Session\Session;
use Wayfinder\Tests\Concerns\MakesRequests;
use Wayfinder\Tests\Concerns\UsesTempDirectory;

final class AppKernelTest extends TestCase
{
    use MakesRequests;
    use UsesTempDirectory;

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTempDirectory();
    }

    private function makeKernel(Router $router, bool $debug = false): AppKernel
    {
        return new AppKernel($router, $debug);
    }

    private function makeRouter(): Router
    {
        return new Router();
    }

    // -------------------------------------------------------------------------
    // Normal dispatch
    // -------------------------------------------------------------------------

    public function testHandleDispatchesMatchingRoute(): void
    {
        $router = $this->makeRouter();
        $router->get('/', static fn (): Response => Response::text('Home'));
        $kernel = $this->makeKernel($router);

        $response = $kernel->handle($this->makeRequest('GET', '/'));

        self::assertSame(200, $response->status());
        self::assertSame('Home', $response->content());
    }

    public function testHandleReturns404ForUnmatchedRoute(): void
    {
        $router = $this->makeRouter();
        $kernel = $this->makeKernel($router);

        $response = $kernel->handle($this->makeRequest('GET', '/missing'));

        self::assertSame(404, $response->status());
    }

    // -------------------------------------------------------------------------
    // Uncaught exceptions
    // -------------------------------------------------------------------------

    public function testHandleReturns500ForUnhandledException(): void
    {
        $router = $this->makeRouter();
        $router->get('/boom', static function (): never {
            throw new \RuntimeException('Something went wrong');
        });
        $kernel = $this->makeKernel($router);

        $response = $kernel->handle($this->makeRequest('GET', '/boom'));

        self::assertSame(500, $response->status());
    }

    public function testDebugModeExposesExceptionDetails(): void
    {
        $router = $this->makeRouter();
        $router->get('/boom', static function (): never {
            throw new \RuntimeException('Secret error details');
        });
        $kernel = $this->makeKernel($router, debug: true);

        $response = $kernel->handle($this->makeRequest('GET', '/boom'));

        self::assertSame(500, $response->status());
        self::assertStringContainsString('Secret error details', $response->content());
        self::assertStringContainsString('RuntimeException', $response->content());
    }

    public function testNonDebugModeHidesExceptionDetails(): void
    {
        $router = $this->makeRouter();
        $router->get('/boom', static function (): never {
            throw new \RuntimeException('Secret error details');
        });
        $kernel = $this->makeKernel($router, debug: false);

        $response = $kernel->handle($this->makeRequest('GET', '/boom'));

        self::assertSame(500, $response->status());
        self::assertStringNotContainsString('Secret error details', $response->content());
    }

    // -------------------------------------------------------------------------
    // ValidationException — JSON path
    // -------------------------------------------------------------------------

    public function testValidationExceptionReturns422JsonForJsonRequest(): void
    {
        $router = $this->makeRouter();
        $router->post('/submit', static function (Request $req): Response {
            throw new ValidationException(['name' => ['Name is required.']]);
        });
        $kernel = $this->makeKernel($router);

        $response = $kernel->handle($this->jsonRequest('POST', '/submit'));

        self::assertSame(422, $response->status());
        $payload = json_decode($response->content(), true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('errors', $payload);
        self::assertArrayHasKey('name', $payload['errors']);
    }

    public function testValidationExceptionJsonResponseHasMessageKey(): void
    {
        $router = $this->makeRouter();
        $router->post('/submit', static function (Request $req): Response {
            throw new ValidationException(['email' => ['Invalid email.']]);
        });
        $kernel = $this->makeKernel($router);

        $response = $kernel->handle($this->jsonRequest('POST', '/submit'));

        $payload = json_decode($response->content(), true);
        self::assertArrayHasKey('message', $payload);
    }

    // -------------------------------------------------------------------------
    // ValidationException — session/redirect path
    // -------------------------------------------------------------------------

    public function testValidationExceptionRedirectsWhenSessionPresent(): void
    {
        $router = $this->makeRouter();
        $router->post('/submit', static function (Request $req): Response {
            throw new ValidationException(['name' => ['Required.']]);
        });
        $kernel = $this->makeKernel($router);

        $session = new Session(bin2hex(random_bytes(20)));
        $request = new Request(
            method: 'POST',
            path: '/submit',
            query: [],
            request: ['name' => ''],
            cookies: [],
            files: [],
            server: [],
            headers: ['referer' => '/form'],
            body: '',
            routeParams: [],
            session: $session,
        );

        $response = $kernel->handle($request);

        self::assertSame(302, $response->status());
    }

    public function testValidationExceptionFlashesErrorsIntoSession(): void
    {
        $router = $this->makeRouter();
        $router->post('/submit', static function (Request $req): Response {
            throw new ValidationException(['name' => ['Required.']]);
        });
        $kernel = $this->makeKernel($router);

        $session = new Session(bin2hex(random_bytes(20)));
        $request = new Request(
            method: 'POST',
            path: '/submit',
            query: [],
            request: ['name' => ''],
            cookies: [],
            files: [],
            server: [],
            headers: ['referer' => '/form'],
            body: '',
            routeParams: [],
            session: $session,
        );

        $kernel->handle($request);

        self::assertNotNull($session->get('_errors'));
        self::assertArrayHasKey('name', $session->get('_errors'));
    }

    public function testValidationExceptionRedirectRejectsExternalReferer(): void
    {
        $router = $this->makeRouter();
        $router->post('/submit', static function (Request $req): Response {
            throw new ValidationException(['name' => ['Required.']]);
        });
        $kernel = $this->makeKernel($router);

        $session = new Session(bin2hex(random_bytes(20)));
        $request = new Request(
            method: 'POST',
            path: '/submit',
            query: [],
            request: ['name' => ''],
            cookies: [],
            files: [],
            server: [],
            headers: ['referer' => 'https://evil.example/outbound'],
            body: '',
            routeParams: [],
            session: $session,
        );

        $response = $kernel->handle($request);

        self::assertSame('/submit', $response->headers()['Location'] ?? null);
    }

    public function testValidationExceptionStripsTokenFromOldInput(): void
    {
        $router = $this->makeRouter();
        $router->post('/submit', static function (Request $req): Response {
            throw new ValidationException(['name' => ['Required.']]);
        });
        $kernel = $this->makeKernel($router);

        $session = new Session(bin2hex(random_bytes(20)));
        $request = new Request(
            method: 'POST',
            path: '/submit',
            query: [],
            request: ['name' => 'Ron', '_token' => 'csrf-abc'],
            cookies: [],
            files: [],
            server: [],
            headers: ['referer' => '/form'],
            body: '',
            routeParams: [],
            session: $session,
        );

        $kernel->handle($request);

        $oldInput = $session->get('_old_input');
        self::assertIsArray($oldInput);
        self::assertArrayNotHasKey('_token', $oldInput);
    }

    // -------------------------------------------------------------------------
    // Logger integration
    // -------------------------------------------------------------------------

    public function testExceptionIsLoggedWithFileLogger(): void
    {
        $logFile = $this->tempDir . '/kernel.log';
        $logger = new \Wayfinder\Logging\FileLogger($logFile);

        $router = $this->makeRouter();
        $router->get('/boom', static function (): never {
            throw new \RuntimeException('Logged error');
        });
        $kernel = new AppKernel($router, false, $logger);

        $kernel->handle($this->makeRequest('GET', '/boom'));

        self::assertFileExists($logFile);
        $content = (string) file_get_contents($logFile);
        self::assertStringContainsString('Logged error', $content);
        self::assertStringContainsString('ERROR', $content);
    }

    // -------------------------------------------------------------------------
    // Middleware ordering
    // -------------------------------------------------------------------------

    public function testGlobalMiddlewareRunsBeforeRouteMiddleware(): void
    {
        $order = [];

        $router = $this->makeRouter();
        $router->addGlobalMiddleware(function (Request $req, callable $next) use (&$order): Response {
            $order[] = 'global';
            return $next($req);
        });
        $router->get(
            '/test',
            static fn (): Response => Response::text('ok'),
            middleware: [function (Request $req, callable $next) use (&$order): Response {
                $order[] = 'route';
                return $next($req);
            }],
        );
        $kernel = $this->makeKernel($router);
        $kernel->handle($this->makeRequest('GET', '/test'));

        self::assertSame(['global', 'route'], $order);
    }

    public function testMiddlewareCanShortCircuitResponse(): void
    {
        $router = $this->makeRouter();
        $router->addGlobalMiddleware(static fn (Request $req, callable $next): Response => Response::text('blocked', 403));
        $router->get('/secret', static fn (): Response => Response::text('secret'));
        $kernel = $this->makeKernel($router);

        $response = $kernel->handle($this->makeRequest('GET', '/secret'));

        self::assertSame(403, $response->status());
        self::assertSame('blocked', $response->content());
    }
}
