<?php

declare(strict_types=1);

namespace Wayfinder\Http;

use Wayfinder\Contracts\Middleware;

final class VerifyCsrfToken implements Middleware
{
    public function __construct(
        private readonly CsrfTokenManager $tokens,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (! $request->hasSession()) {
            throw new \RuntimeException('CSRF protection requires an active session.');
        }

        $this->tokens->token($request->session());

        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $token = $request->string('_token')
            ?: $request->header('x-csrf-token')
            ?: $request->header('x-xsrf-token');

        if (! $this->tokens->matches($request->session(), is_string($token) ? $token : null)) {
            return Response::json([
                'message' => 'CSRF token mismatch.',
            ], 419);
        }

        return $next($request);
    }
}
