<?php

declare(strict_types=1);

namespace Wayfinder\Auth;

use Wayfinder\Contracts\Middleware;
use Wayfinder\Http\Request;
use Wayfinder\Http\Response;

final class Authenticate implements Middleware
{
    public function __construct(
        private readonly AuthManager $auth,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        if ($this->auth->guest()) {
            return Response::json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return $next($request);
    }
}
