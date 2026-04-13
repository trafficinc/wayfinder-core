<?php

declare(strict_types=1);

namespace Wayfinder\Http;

use Wayfinder\Contracts\Middleware;

final class NoCache implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        return $response
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }
}
