<?php

declare(strict_types=1);

namespace Wayfinder\Security;

use Wayfinder\Http\Request;

final class UrlSigner
{
    public function __construct(
        private readonly string $key,
        private readonly string $appUrl = '',
    ) {
        if (trim($this->key) === '') {
            throw new \RuntimeException('URL signing key must not be empty.');
        }
    }

    /**
     * @param array<string, scalar|null> $query
     */
    public function sign(string $path, array $query = [], ?int $expiresAt = null, bool $absolute = true): string
    {
        $params = $this->normalizeQuery($query);

        if ($expiresAt !== null) {
            $params['expires'] = (string) $expiresAt;
        }

        $params['signature'] = $this->signatureFor($path, $params);

        $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $url = $this->normalizePath($path);

        if ($absolute) {
            $base = rtrim($this->appUrl, '/');

            if ($base === '') {
                throw new \RuntimeException('APP_URL must be configured to generate absolute signed URLs.');
            }

            $url = $base . $url;
        }

        return $queryString === '' ? $url : $url . '?' . $queryString;
    }

    public function hasValidSignature(Request|string $requestOrUrl): bool
    {
        [$path, $query] = $this->parseInput($requestOrUrl);
        $signature = $query['signature'] ?? null;

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        if (isset($query['expires']) && ctype_digit((string) $query['expires']) && (int) $query['expires'] < time()) {
            return false;
        }

        return hash_equals($signature, $this->signatureFor($path, $query));
    }

    public function signatureFor(string $path, array $query): string
    {
        unset($query['signature']);
        $query = $this->normalizeQuery($query);
        $payload = $this->normalizePath($path);
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        if ($queryString !== '') {
            $payload .= '?' . $queryString;
        }

        return hash_hmac('sha256', $payload, $this->key);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, string>
     */
    private function normalizeQuery(array $query): array
    {
        $normalized = [];

        foreach ($query as $key => $value) {
            if ($key === 'signature') {
                continue;
            }

            if (! is_string($key)) {
                continue;
            }

            if (is_array($value)) {
                throw new \InvalidArgumentException('Signed URL query values must be scalar.');
            }

            if ($value === null) {
                continue;
            }

            $normalized[$key] = (string) $value;
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function parseInput(Request|string $requestOrUrl): array
    {
        if ($requestOrUrl instanceof Request) {
            return [$requestOrUrl->path(), $requestOrUrl->query()];
        }

        $path = parse_url($requestOrUrl, PHP_URL_PATH);
        $queryString = parse_url($requestOrUrl, PHP_URL_QUERY);
        $query = [];

        if (is_string($queryString) && $queryString !== '') {
            parse_str($queryString, $query);
        }

        return [is_string($path) && $path !== '' ? $path : '/', $query];
    }

    private function normalizePath(string $path): string
    {
        return $path === '' || $path === '/' ? '/' : '/' . ltrim($path, '/');
    }
}
