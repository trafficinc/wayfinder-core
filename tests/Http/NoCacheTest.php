<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Http;

use PHPUnit\Framework\TestCase;
use Wayfinder\Http\NoCache;
use Wayfinder\Http\Request;
use Wayfinder\Http\Response;

final class NoCacheTest extends TestCase
{
    public function test_it_applies_no_cache_headers(): void
    {
        $middleware = new NoCache();
        $request = new Request(
            'GET',
            '/dashboard',
            [],
            [],
            [],
            [],
            [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/dashboard',
                'SCRIPT_NAME' => '/index.php',
            ],
            [],
            '',
        );

        $response = $middleware->handle($request, static fn (): Response => Response::html('<h1>Dashboard</h1>'));

        self::assertSame('no-store, no-cache, must-revalidate, max-age=0', $response->headers()['Cache-Control'] ?? null);
        self::assertSame('no-cache', $response->headers()['Pragma'] ?? null);
        self::assertSame('0', $response->headers()['Expires'] ?? null);
    }
}
