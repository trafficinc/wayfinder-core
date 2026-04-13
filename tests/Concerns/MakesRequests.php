<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Concerns;

use Wayfinder\Http\Request;

trait MakesRequests
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $query
     * @param array<string, string> $cookies
     * @param array<string, string> $headers
     */
    protected function makeRequest(
        string $method = 'GET',
        string $path = '/',
        array $data = [],
        array $query = [],
        array $cookies = [],
        array $headers = [],
        string $body = '',
    ): Request {
        return new Request(
            method: strtoupper($method),
            path: $path,
            query: $query,
            request: $data,
            cookies: $cookies,
            files: [],
            server: [],
            headers: $headers,
            body: $body,
            routeParams: [],
        );
    }

    protected function jsonRequest(string $method = 'GET', string $path = '/', array $data = []): Request
    {
        return $this->makeRequest($method, $path, $data, headers: ['accept' => 'application/json']);
    }
}
