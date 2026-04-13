<?php

declare(strict_types=1);

namespace Wayfinder\Routing;

use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Wayfinder\Contracts\Container;
use Wayfinder\Contracts\EventDispatcher;
use Wayfinder\Contracts\Middleware;
use Wayfinder\Http\FormRequest;
use Wayfinder\Http\Request;
use Wayfinder\Http\Response;
use Wayfinder\Support\NullContainer;
use Wayfinder\Support\NullEventDispatcher;

final class Router
{
    /**
     * @var list<Route>
     */
    private array $routes = [];

    /**
     * @var array<string, Route>
     */
    private array $namedRoutes = [];

    /**
     * @var list<callable|string>
     */
    private array $globalMiddleware = [];

    /**
     * @var array<string, callable|string>
     */
    private array $middlewareAliases = [];

    /**
     * @var array<string, list<callable|string>>
     */
    private array $middlewareGroups = [];

    /**
     * @var list<array{prefix: string, name: string, middleware: list<callable|string>}>
     */
    private array $groupStack = [];

    public function __construct(
        private readonly Container $container = new NullContainer(),
        private readonly EventDispatcher $events = new NullEventDispatcher(),
        private readonly string $controllerNamespace = 'App\\Controllers\\',
    ) {
    }

    /**
     * @param callable|array{0: class-string|string, 1: string}|string $handler
     * @param list<callable|string> $middleware
     */
    public function get(string $path, mixed $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->add('GET', $path, $handler, $name, $middleware);
    }

    /**
     * @param callable|array{0: class-string|string, 1: string}|string $handler
     * @param list<callable|string> $middleware
     */
    public function post(string $path, mixed $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->add('POST', $path, $handler, $name, $middleware);
    }

    /**
     * @param callable|array{0: class-string|string, 1: string}|string $handler
     * @param list<callable|string> $middleware
     */
    public function put(string $path, mixed $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->add('PUT', $path, $handler, $name, $middleware);
    }

    /**
     * @param callable|array{0: class-string|string, 1: string}|string $handler
     * @param list<callable|string> $middleware
     */
    public function patch(string $path, mixed $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->add('PATCH', $path, $handler, $name, $middleware);
    }

    /**
     * @param callable|array{0: class-string|string, 1: string}|string $handler
     * @param list<callable|string> $middleware
     */
    public function delete(string $path, mixed $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->add('DELETE', $path, $handler, $name, $middleware);
    }

    /**
     * @param callable|array{0: class-string|string, 1: string}|string $handler
     * @param list<callable|string> $middleware
     */
    public function options(string $path, mixed $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->add('OPTIONS', $path, $handler, $name, $middleware);
    }

    /**
     * @param callable|array{0: class-string|string, 1: string}|string $handler
     * @param list<callable|string> $middleware
     */
    public function any(string $path, mixed $handler, ?string $name = null, array $middleware = []): self
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $routeName = $name === null ? null : sprintf('%s.%s', $name, strtolower($method));
            $this->add($method, $path, $handler, $routeName, $middleware);
        }

        return $this;
    }

    /**
     * @param callable|string $middleware
     */
    public function addGlobalMiddleware(callable|string $middleware): self
    {
        $this->globalMiddleware[] = $middleware;

        return $this;
    }

    /**
     * @param array<int, callable|string> $middleware
     */
    public function addGlobalMiddlewareGroup(array $middleware): self
    {
        foreach ($middleware as $entry) {
            $this->globalMiddleware[] = $entry;
        }

        return $this;
    }

    public function aliasMiddleware(string $alias, callable|string $middleware): self
    {
        $this->middlewareAliases[$alias] = $middleware;

        return $this;
    }

    /**
     * @param list<callable|string> $middleware
     */
    public function middlewareGroup(string $name, array $middleware): self
    {
        $this->middlewareGroups[$name] = $middleware;

        return $this;
    }

    /**
     * @param array{prefix?: string, name?: string, middleware?: list<callable|string>} $attributes
     */
    public function group(array $attributes, callable $routes): self
    {
        $this->groupStack[] = [
            'prefix' => $this->normalizeGroupPrefix((string) ($attributes['prefix'] ?? '')),
            'name' => (string) ($attributes['name'] ?? ''),
            'middleware' => $attributes['middleware'] ?? [],
        ];

        try {
            $routes($this);
        } finally {
            array_pop($this->groupStack);
        }

        return $this;
    }

    public function urlFor(string $name, array $params = []): ?string
    {
        $route = $this->namedRoutes[$name] ?? null;

        if ($route === null) {
            return null;
        }

        $url = $route->path();

        foreach ($params as $key => $value) {
            $url = str_replace('{' . $key . '}', (string) $value, $url);
        }

        return $url;
    }

    /**
     * @param callable|array{0: class-string|string, 1: string}|string $handler
     * @param list<callable|string> $middleware
     */
    public function add(string $method, string $path, mixed $handler, ?string $name = null, array $middleware = []): self
    {
        $groupPrefix = '';
        $groupName = '';
        $groupMiddleware = [];

        foreach ($this->groupStack as $group) {
            $groupPrefix .= $group['prefix'];
            $groupName .= $group['name'];
            array_push($groupMiddleware, ...$group['middleware']);
        }

        $resolvedPath = $this->joinPaths($groupPrefix, $path);
        $resolvedName = $name === null ? null : $groupName . $name;
        $route = new Route(
            method: strtoupper($method),
            path: $this->normalizePath($resolvedPath),
            handler: $this->normalizeHandler($handler),
            name: $resolvedName,
            middleware: $this->expandMiddleware(array_merge($groupMiddleware, $middleware)),
            pattern: $this->buildRoutePattern($resolvedPath),
        );

        $this->routes[] = $route;

        if ($resolvedName !== null) {
            $this->namedRoutes[$resolvedName] = $route;
        }

        return $this;
    }

    public function dispatch(Request $request): Response
    {
        $this->events->dispatch('pre.controller', [$request->method(), $request->path()]);

        $match = $this->match($request);

        if ($match === null) {
            $this->events->dispatch('route.not_found', [$request->method(), $request->path()]);
            return Response::notFound(sprintf('No route matches [%s] %s.', $request->method(), $request->path()));
        }

        $request = $request->withRouteParams($match['params']);

        $response = $this->executeMiddlewareStack(
            array_merge($this->globalMiddleware, $match['route']->middleware()),
            $request,
            fn (Request $request): Response => $this->invokeRoute($match['route'], $request, $match['params']),
        );

        $this->events->dispatch('post.controller', [$request->method(), $request->path(), $response->status()]);

        return $response;
    }

    /**
     * @return array{route: Route, params: array<string, string>}|null
     */
    private function match(Request $request): ?array
    {
        $method = strtoupper($request->method());
        $path = $this->normalizePath($request->path());

        foreach ($this->routes as $route) {
            $params = $route->matches($method, $path);

            if ($params !== null) {
                return ['route' => $route, 'params' => $params];
            }
        }

        return null;
    }

    /**
     * @param list<callable|string> $middlewareStack
     * @param callable(Request): Response $destination
     */
    private function executeMiddlewareStack(array $middlewareStack, Request $request, callable $destination): Response
    {
        $next = array_reduce(
            array_reverse($middlewareStack),
            function (callable $next, callable|string $middleware): callable {
                return function (Request $request) use ($middleware, $next): Response {
                    [$instance, $parameters] = $this->resolveMiddleware($middleware);

                    return $instance->handle($request, $next, ...$parameters);
                };
            },
            $destination,
        );

        return $next($request);
    }

    /**
     * @param callable|string $middleware
     * @return array{0: Middleware, 1: list<string>}
     */
    private function resolveMiddleware(callable|string $middleware): array
    {
        $parameters = [];

        if (is_string($middleware)) {
            [$middleware, $parameters] = $this->resolveAliasedMiddleware($middleware);
        }

        if (is_callable($middleware) && ! is_string($middleware)) {
            return [new class ($middleware, $parameters) implements Middleware {
                public function __construct(
                    private readonly mixed $middleware,
                    private readonly array $parameters,
                ) {
                }

                public function handle(Request $request, callable $next, string ...$ignored): Response
                {
                    $result = ($this->middleware)($request, $next, ...$this->parameters);

                    return $result instanceof Response ? $result : Response::html((string) $result);
                }
            }, []];
        }

        $instance = $this->container->has($middleware)
            ? $this->container->get($middleware)
            : new $middleware();

        if (! $instance instanceof Middleware) {
            throw new \RuntimeException(sprintf('Middleware [%s] must implement %s.', $middleware, Middleware::class));
        }

        return [$instance, $parameters];
    }

    /**
     * @return array{0: callable|string, 1: list<string>}
     */
    private function resolveAliasedMiddleware(string $middleware): array
    {
        [$alias, $parameters] = $this->parseMiddlewareString($middleware);

        if (isset($this->middlewareAliases[$alias])) {
            return [$this->middlewareAliases[$alias], $parameters];
        }

        if (isset($this->middlewareGroups[$alias])) {
            throw new \RuntimeException(sprintf(
                'Middleware group [%s] must be expanded before resolution.',
                $alias,
            ));
        }

        return [$alias, $parameters];
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function parseMiddlewareString(string $middleware): array
    {
        if (! str_contains($middleware, ':')) {
            return [$middleware, []];
        }

        [$name, $parameterString] = explode(':', $middleware, 2);
        $parameters = $parameterString === '' ? [] : array_map('trim', explode(',', $parameterString));

        return [$name, $parameters];
    }

    /**
     * @param array<string, string> $params
     */
    private function invokeRoute(Route $route, Request $request, array $params): Response
    {
        $handler = $route->handler();

        if (is_callable($handler)) {
            $result = $handler(...$this->resolveCallableArguments(
                new ReflectionFunction(\Closure::fromCallable($handler)),
                $request,
                $params,
            ));

            return $this->normalizeResponse($result);
        }

        [$controller, $action] = $handler;
        $instance = $this->resolveController($controller);

        if (! method_exists($instance, $action)) {
            throw new \RuntimeException(sprintf('Action [%s] does not exist on [%s].', $action, $instance::class));
        }

        $method = new ReflectionMethod($instance, $action);
        $result = $instance->{$action}(...$this->resolveCallableArguments($method, $request, $params));

        return $this->normalizeResponse($result);
    }

    /**
     * @param ReflectionFunction|ReflectionMethod $callable
     * @param array<string, string> $params
     * @return list<mixed>
     */
    private function resolveCallableArguments(ReflectionFunction|ReflectionMethod $callable, Request $request, array $params): array
    {
        $arguments = [];

        foreach ($callable->getParameters() as $parameter) {
            $arguments[] = $this->resolveArgument($parameter, $request, $params);
        }

        return $arguments;
    }

    /**
     * @param array<string, string> $params
     */
    private function resolveArgument(ReflectionParameter $parameter, Request $request, array $params): mixed
    {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            $name = $type->getName();

            if ($name === Request::class) {
                return $request;
            }

            if (is_a($name, FormRequest::class, true)) {
                return $name::fromRequest($request);
            }

            if ($name === self::class) {
                return $this;
            }

            if ($this->container->has($name)) {
                return $this->container->get($name);
            }
        }

        if (array_key_exists($parameter->getName(), $params)) {
            return $params[$parameter->getName()];
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw new \RuntimeException(sprintf('Unable to resolve parameter [$%s].', $parameter->getName()));
    }

    private function resolveController(string $controller): object
    {
        $controllerClass = class_exists($controller)
            ? $controller
            : $this->controllerNamespace . ltrim($controller, '\\');

        if (! class_exists($controllerClass)) {
            throw new \RuntimeException(sprintf('Controller [%s] was not found.', $controllerClass));
        }

        if ($this->container->has($controllerClass)) {
            $instance = $this->container->get($controllerClass);

            if (is_object($instance)) {
                return $instance;
            }
        }

        return new $controllerClass();
    }

    private function normalizeResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_string($result)) {
            return Response::html($result);
        }

        if ($result === null) {
            return Response::make('', 204);
        }

        return Response::html((string) $result);
    }

    /**
     * @param callable|array{0: class-string|string, 1: string}|string $handler
     * @return callable|array{0: class-string|string, 1: string}
     */
    private function normalizeHandler(mixed $handler): callable|array
    {
        if (is_callable($handler)) {
            return $handler;
        }

        if (is_string($handler) && class_exists($handler)) {
            return [$handler, '__invoke'];
        }

        if (is_array($handler) && count($handler) === 2 && is_string($handler[0]) && is_string($handler[1])) {
            return [$handler[0], $handler[1]];
        }

        if (is_string($handler) && str_contains($handler, '@')) {
            [$controller, $action] = explode('@', $handler, 2);

            return [$controller, $action];
        }

        throw new \InvalidArgumentException('Route handlers must be a callable, [controller, action], or "Controller@action" string.');
    }

    private function buildRoutePattern(string $path): string
    {
        $normalized = $this->normalizePath($path);
        $pattern = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $normalized);

        return sprintf('#^%s$#', $pattern);
    }

    private function normalizePath(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        return '/' . trim($path, '/');
    }

    private function normalizeGroupPrefix(string $prefix): string
    {
        if ($prefix === '' || $prefix === '/') {
            return '';
        }

        return '/' . trim($prefix, '/');
    }

    private function joinPaths(string $prefix, string $path): string
    {
        if ($prefix === '') {
            return $path;
        }

        if ($path === '' || $path === '/') {
            return $prefix;
        }

        return rtrim($prefix, '/') . '/' . ltrim($path, '/');
    }

    /**
     * @param list<callable|string> $middleware
     * @return list<callable|string>
     */
    private function expandMiddleware(array $middleware): array
    {
        $expanded = [];

        foreach ($middleware as $entry) {
            if (is_string($entry) && isset($this->middlewareGroups[$entry])) {
                array_push($expanded, ...$this->expandMiddleware($this->middlewareGroups[$entry]));
                continue;
            }

            $expanded[] = $entry;
        }

        return $expanded;
    }

    /**
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * @return array<string, Route>
     */
    public function namedRoutes(): array
    {
        return $this->namedRoutes;
    }

    /**
     * @return list<array{
     *     method: string,
     *     path: string,
     *     handler: array{0: class-string|string, 1: string}|string,
     *     name: string|null,
     *     middleware: list<string>
     * }>
     */
    public function cacheManifest(): array
    {
        $manifest = [];

        foreach ($this->routes as $route) {
            $handler = $route->handler();

            if ($handler instanceof \Closure) {
                throw new \RuntimeException(sprintf(
                    'Unable to cache route [%s %s]: closure handlers are not supported.',
                    $route->method(),
                    $route->path(),
                ));
            }

            foreach ($route->middleware() as $middleware) {
                if (! is_string($middleware)) {
                    throw new \RuntimeException(sprintf(
                        'Unable to cache route [%s %s]: closure middleware is not supported.',
                        $route->method(),
                        $route->path(),
                    ));
                }
            }

            $manifest[] = [
                'method' => $route->method(),
                'path' => $route->path(),
                'handler' => is_array($handler) ? $handler : (string) $handler,
                'name' => $route->name(),
                'middleware' => $route->middleware(),
            ];
        }

        return $manifest;
    }

    /**
     * @param list<array{
     *     method: string,
     *     path: string,
     *     handler: array{0: class-string|string, 1: string}|string,
     *     name?: string|null,
     *     middleware?: list<string>
     * }> $manifest
     */
    public function loadCachedRoutes(array $manifest): void
    {
        foreach ($manifest as $route) {
            $this->add(
                $route['method'],
                $route['path'],
                $route['handler'],
                $route['name'] ?? null,
                $route['middleware'] ?? [],
            );
        }
    }
}
