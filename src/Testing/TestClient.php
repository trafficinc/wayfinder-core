<?php

declare(strict_types=1);

namespace Wayfinder\Testing;

use Wayfinder\Auth\AuthManager;
use Wayfinder\Contracts\Container;
use Wayfinder\Foundation\AppKernel;
use Wayfinder\Http\Request;
use Wayfinder\Http\Response;
use Wayfinder\Session\Session;
use Wayfinder\Session\SessionStore;
use Wayfinder\Support\Config;

final class TestClient
{
    /**
     * @var array<string, string>
     */
    private array $cookies = [];

    /**
     * @var array<string, string>
     */
    private array $headers = [];

    public function __construct(
        private readonly AppKernel $kernel,
        private readonly ?Container $container = null,
    ) {
    }

    public function get(string $uri, array $headers = []): TestResponse
    {
        return $this->request('GET', $uri, [], $headers);
    }

    public function post(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('POST', $uri, $data, $headers);
    }

    public function put(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('PUT', $uri, $data, $headers);
    }

    public function patch(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('PATCH', $uri, $data, $headers);
    }

    public function delete(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('DELETE', $uri, $data, $headers);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function request(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        [$path, $query] = $this->parseUri($uri);

        $request = new Request(
            strtoupper($method),
            $path,
            in_array(strtoupper($method), ['GET', 'HEAD'], true) ? [...$query, ...$data] : $query,
            in_array(strtoupper($method), ['GET', 'HEAD'], true) ? [] : $data,
            $this->cookies,
            [],
            [
                'REQUEST_METHOD' => strtoupper($method),
                'REQUEST_URI' => $uri,
                'SCRIPT_NAME' => '/index.php',
            ],
            $this->normalizeHeaders([...$this->headers, ...$headers]),
            '',
        );

        $response = $this->kernel->handle($request);
        $this->storeResponseCookies($response);

        return new TestResponse($response);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    public function withCookie(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->cookies[$name] = $value;

        return $clone;
    }

    public function flushCookies(): self
    {
        $clone = clone $this;
        $clone->cookies = [];

        return $clone;
    }

    public function actingAs(int|string $userId): self
    {
        if ($this->container === null) {
            throw new \RuntimeException('actingAs() requires a container-aware test client.');
        }

        $store = $this->container->get(SessionStore::class);
        $config = $this->container->get(Config::class);
        $auth = $this->container->get(AuthManager::class);

        if (! $store instanceof SessionStore || ! $config instanceof Config || ! $auth instanceof AuthManager) {
            throw new \RuntimeException('Unable to resolve test auth dependencies from the container.');
        }

        $session = new Session(bin2hex(random_bytes(20)));
        $session->put((string) $config->get('auth.session_key', 'auth.user_id'), $userId);
        $store->save($session);

        return $this->withCookie(
            (string) $config->get('session.cookie', 'wayfinder_session'),
            $session->id(),
        );
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function parseUri(string $uri): array
    {
        $path = parse_url($uri, PHP_URL_PATH);
        $queryString = parse_url($uri, PHP_URL_QUERY);
        $query = [];

        if (is_string($queryString) && $queryString !== '') {
            parse_str($queryString, $query);
        }

        return [is_string($path) && $path !== '' ? $path : '/', $query];
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }

        return $normalized;
    }

    private function storeResponseCookies(Response $response): void
    {
        foreach ($response->cookies() as $cookie) {
            $this->cookies[$cookie->name()] = $cookie->value();
        }
    }
}
