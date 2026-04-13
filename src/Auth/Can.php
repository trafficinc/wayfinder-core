<?php

declare(strict_types=1);

namespace Wayfinder\Auth;

use Wayfinder\Contracts\Middleware;
use Wayfinder\Http\Request;
use Wayfinder\Http\Response;

final class Can implements Middleware
{
    public function __construct(
        private readonly Gate $gate,
    ) {
    }

    public function handle(Request $request, callable $next, string $ability = '', string ...$arguments): Response
    {
        if ($ability === '' || $this->gate->denies($ability, $request, ...$arguments)) {
            return Response::text('Forbidden', 403);
        }

        return $next($request);
    }
}
