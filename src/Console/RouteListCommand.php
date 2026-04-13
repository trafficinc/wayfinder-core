<?php

declare(strict_types=1);

namespace Wayfinder\Console;

use Wayfinder\Routing\Route;
use Wayfinder\Routing\Router;

final class RouteListCommand implements Command
{
    public function __construct(
        private readonly Router $router,
    ) {
    }

    public function name(): string
    {
        return 'route:list';
    }

    public function description(): string
    {
        return 'List registered routes.';
    }

    public function handle(array $arguments = []): int
    {
        $routes = $this->router->routes();

        if ($routes === []) {
            fwrite(STDOUT, "No routes registered.\n");

            return 0;
        }

        fwrite(STDOUT, "METHOD   URI                  NAME                HANDLER                          MIDDLEWARE\n");

        foreach ($routes as $route) {
            fwrite(STDOUT, sprintf(
                "%-8s %-20s %-19s %-32s %s\n",
                $route->method(),
                $route->path(),
                $route->name() ?? '-',
                $this->describeHandler($route),
                $this->describeMiddleware($route),
            ));
        }

        return 0;
    }

    private function describeHandler(Route $route): string
    {
        $handler = $route->handler();

        if (is_array($handler)) {
            return sprintf('%s@%s', $handler[0], $handler[1]);
        }

        if ($handler instanceof \Closure) {
            return 'Closure';
        }

        if (is_string($handler)) {
            return $handler;
        }

        return 'Callable';
    }

    private function describeMiddleware(Route $route): string
    {
        $middleware = array_map(
            static function (mixed $entry): string {
                if ($entry instanceof \Closure) {
                    return 'Closure';
                }

                if (is_string($entry)) {
                    return $entry;
                }

                if (is_object($entry)) {
                    return $entry::class;
                }

                return 'callable';
            },
            $route->middleware(),
        );

        return $middleware === [] ? '-' : implode(',', $middleware);
    }
}
