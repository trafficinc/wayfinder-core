<?php

declare(strict_types=1);

namespace Wayfinder\Security;

use Wayfinder\Contracts\Middleware;
use Wayfinder\Http\Request;
use Wayfinder\Http\Response;

final class ValidateSignature implements Middleware
{
    public function __construct(
        private readonly UrlSigner $signer,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (! $this->signer->hasValidSignature($request)) {
            return Response::json([
                'message' => 'Invalid signature.',
            ], 403);
        }

        return $next($request);
    }
}
