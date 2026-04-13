<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Routing;

use PHPUnit\Framework\TestCase;
use Wayfinder\Contracts\Middleware;
use Wayfinder\Http\Request;
use Wayfinder\Http\Response;
use Wayfinder\Routing\Router;
use Wayfinder\Tests\Concerns\MakesRequests;

final class RouterTest extends TestCase
{
    use MakesRequests;

    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    // -------------------------------------------------------------------------
    // Basic routing
    // -------------------------------------------------------------------------

    public function testGetRouteMatches(): void
    {
        $this->router->get('/', static fn (): Response => Response::text('home'));

        $response = $this->router->dispatch($this->makeRequest('GET', '/'));

        self::assertSame(200, $response->status());
        self::assertSame('home', $response->content());
    }

    public function testPostRouteMatches(): void
    {
        $this->router->post('/submit', static fn (): Response => Response::text('submitted'));

        $response = $this->router->dispatch($this->makeRequest('POST', '/submit'));

        self::assertSame(200, $response->status());
    }

    public function testMethodMismatchReturns404(): void
    {
        $this->router->get('/page', static fn (): Response => Response::text('page'));

        $response = $this->router->dispatch($this->makeRequest('POST', '/page'));

        self::assertSame(404, $response->status());
    }

    public function testUnregisteredPathReturns404(): void
    {
        $response = $this->router->dispatch($this->makeRequest('GET', '/missing'));

        self::assertSame(404, $response->status());
    }

    // -------------------------------------------------------------------------
    // Route parameters
    // -------------------------------------------------------------------------

    public function testRouteParameterInjectedIntoHandler(): void
    {
        $this->router->get('/users/{id}', static function (string $id): Response {
            return Response::text("user:{$id}");
        });

        $response = $this->router->dispatch($this->makeRequest('GET', '/users/42'));

        self::assertSame('user:42', $response->content());
    }

    public function testMultipleRouteParameters(): void
    {
        $this->router->get('/posts/{year}/{slug}', static function (string $year, string $slug): Response {
            return Response::text("{$year}/{$slug}");
        });

        $response = $this->router->dispatch($this->makeRequest('GET', '/posts/2024/hello-world'));

        self::assertSame('2024/hello-world', $response->content());
    }

    public function testRouteParametersSetOnRequest(): void
    {
        $captured = [];
        $this->router->get('/items/{id}', static function (Request $req) use (&$captured): Response {
            $captured = $req->routeParams();
            return Response::text('ok');
        });

        $this->router->dispatch($this->makeRequest('GET', '/items/99'));

        self::assertSame(['id' => '99'], $captured);
    }

    // -------------------------------------------------------------------------
    // Named routes
    // -------------------------------------------------------------------------

    public function testUrlForGeneratesCorrectUrl(): void
    {
        $this->router->get('/users/{id}/profile', static fn (): Response => Response::text('ok'), name: 'user.profile');

        $url = $this->router->urlFor('user.profile', ['id' => '7']);

        self::assertSame('/users/7/profile', $url);
    }

    public function testUrlForReturnsNullForUnknownName(): void
    {
        self::assertNull($this->router->urlFor('no.such.route'));
    }

    // -------------------------------------------------------------------------
    // Middleware ordering
    // -------------------------------------------------------------------------

    public function testGlobalMiddlewareRunsForEveryRoute(): void
    {
        $calls = [];
        $this->router->addGlobalMiddleware(function (Request $req, callable $next) use (&$calls): Response {
            $calls[] = 'global';
            return $next($req);
        });
        $this->router->get('/a', static fn (): Response => Response::text('a'));
        $this->router->get('/b', static fn (): Response => Response::text('b'));

        $this->router->dispatch($this->makeRequest('GET', '/a'));
        $this->router->dispatch($this->makeRequest('GET', '/b'));

        self::assertSame(['global', 'global'], $calls);
    }

    public function testRouteMiddlewareRunsInRegisteredOrder(): void
    {
        $order = [];
        $this->router->get(
            '/ordered',
            static fn (): Response => Response::text('ok'),
            middleware: [
                function (Request $req, callable $next) use (&$order): Response {
                    $order[] = 'first';
                    return $next($req);
                },
                function (Request $req, callable $next) use (&$order): Response {
                    $order[] = 'second';
                    return $next($req);
                },
            ],
        );

        $this->router->dispatch($this->makeRequest('GET', '/ordered'));

        self::assertSame(['first', 'second'], $order);
    }

    public function testMiddlewareCanModifyResponse(): void
    {
        $this->router->addGlobalMiddleware(static function (Request $req, callable $next): Response {
            $response = $next($req);
            return $response->header('X-Test', 'added');
        });
        $this->router->get('/', static fn (): Response => Response::text('ok'));

        $response = $this->router->dispatch($this->makeRequest('GET', '/'));

        self::assertSame('added', $response->headers()['X-Test']);
    }

    // -------------------------------------------------------------------------
    // Middleware alias
    // -------------------------------------------------------------------------

    public function testAliasedMiddlewareIsResolved(): void
    {
        $called = false;
        $this->router->aliasMiddleware('logger', function (Request $req, callable $next) use (&$called): Response {
            $called = true;
            return $next($req);
        });
        $this->router->get('/aliased', static fn (): Response => Response::text('ok'), middleware: ['logger']);

        $this->router->dispatch($this->makeRequest('GET', '/aliased'));

        self::assertTrue($called);
    }

    // -------------------------------------------------------------------------
    // Middleware group
    // -------------------------------------------------------------------------

    public function testMiddlewareGroupIsExpandedOnRouteRegistration(): void
    {
        $order = [];
        $this->router->middlewareGroup('web', [
            function (Request $req, callable $next) use (&$order): Response {
                $order[] = 'mw1';
                return $next($req);
            },
            function (Request $req, callable $next) use (&$order): Response {
                $order[] = 'mw2';
                return $next($req);
            },
        ]);
        $this->router->get('/grouped', static fn (): Response => Response::text('ok'), middleware: ['web']);

        $this->router->dispatch($this->makeRequest('GET', '/grouped'));

        self::assertSame(['mw1', 'mw2'], $order);
    }

    // -------------------------------------------------------------------------
    // Route groups
    // -------------------------------------------------------------------------

    public function testGroupPrefixAppliedToRoutes(): void
    {
        $this->router->group(['prefix' => '/api'], function (Router $router): void {
            $router->get('/users', static fn (): Response => Response::text('users'));
        });

        $response = $this->router->dispatch($this->makeRequest('GET', '/api/users'));

        self::assertSame(200, $response->status());
    }

    public function testGroupNamePrefixAppliedToNamedRoutes(): void
    {
        $this->router->group(['name' => 'api.'], function (Router $router): void {
            $router->get('/users', static fn (): Response => Response::text('users'), name: 'users.index');
        });

        self::assertNotNull($this->router->urlFor('api.users.index'));
    }

    // -------------------------------------------------------------------------
    // Handler formats
    // -------------------------------------------------------------------------

    public function testStringResponseAutoWrappedInHtmlResponse(): void
    {
        $this->router->get('/html', static fn (): string => '<h1>Hello</h1>');

        $response = $this->router->dispatch($this->makeRequest('GET', '/html'));

        self::assertSame(200, $response->status());
        self::assertSame('<h1>Hello</h1>', $response->content());
    }

    public function testNullHandlerResultsIn204(): void
    {
        $this->router->get('/null', static fn (): null => null);

        $response = $this->router->dispatch($this->makeRequest('GET', '/null'));

        self::assertSame(204, $response->status());
    }

    // -------------------------------------------------------------------------
    // Cache manifest round-trip
    // -------------------------------------------------------------------------

    public function testCacheManifestRejectsClosureHandlers(): void
    {
        $this->router->get('/closure', static fn (): Response => Response::text('ok'));

        $this->expectException(\RuntimeException::class);
        $this->router->cacheManifest();
    }

    public function testLoadCachedRoutesRestoresRoutes(): void
    {
        $manifest = [
            [
                'method' => 'GET',
                'path' => '/cached',
                'handler' => ['Wayfinder\\Tests\\Routing\\FakeController', 'index'],
                'name' => 'cached.index',
                'middleware' => [],
            ],
        ];

        $router = new Router();
        $router->loadCachedRoutes($manifest);

        self::assertCount(1, $router->routes());
        self::assertSame('/cached', $router->routes()[0]->path());
        self::assertNotNull($router->urlFor('cached.index'));
    }

    // -------------------------------------------------------------------------
    // Request injection
    // -------------------------------------------------------------------------

    public function testRequestInjectedByType(): void
    {
        $captured = null;
        $this->router->get('/req', static function (Request $req) use (&$captured): Response {
            $captured = $req;
            return Response::text('ok');
        });

        $sent = $this->makeRequest('GET', '/req');
        $this->router->dispatch($sent);

        self::assertInstanceOf(Request::class, $captured);
    }

    // -------------------------------------------------------------------------
    // any()
    // -------------------------------------------------------------------------

    public function testAnyMatchesAllMethods(): void
    {
        $this->router->any('/any', static fn (): Response => Response::text('any'));

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $response = $this->router->dispatch($this->makeRequest($method, '/any'));
            self::assertSame(200, $response->status(), "Failed for method {$method}");
        }
    }
}
