<?php

declare(strict_types=1);

namespace Wayfinder\Http;

use Wayfinder\Database\DB;
use Wayfinder\Session\Session;

class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $request
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $files
     * @param array<string, mixed> $server
     * @param array<string, string> $headers
     * @param array<string, string> $routeParams
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $request,
        private readonly array $cookies,
        private readonly array $files,
        private readonly array $server,
        private readonly array $headers,
        private readonly string $body,
        private readonly array $routeParams = [],
        private readonly ?Session $session = null,
    ) {
    }

    public static function fromGlobals(): self
    {
        $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        return new self(
            method: strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            path: is_string($uriPath) && $uriPath !== '' ? $uriPath : '/',
            query: $_GET,
            request: $_POST,
            cookies: $_COOKIE,
            files: $_FILES,
            server: $_SERVER,
            headers: self::headersFromServer($_SERVER),
            body: file_get_contents('php://input') ?: '',
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, mixed>
     */
    public function query(): array
    {
        return $this->query;
    }

    /**
     * @return array<string, mixed>
     */
    public function request(): array
    {
        return $this->request;
    }

    /**
     * @return array<string, mixed>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * @return array<string, mixed>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * @return array<string, mixed>
     */
    public function server(): array
    {
        return $this->server;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function redirectTarget(?string $fallback = null): string
    {
        $explicit = $this->input('_redirect');

        if (is_string($explicit) && $explicit !== '') {
            return $this->sanitizeRedirectTarget(
                $explicit,
                $fallback ?? $this->path,
            );
        }

        return $this->sanitizeRedirectTarget(
            $this->header('referer'),
            $fallback ?? $this->path,
        );
    }

    public function body(): string
    {
        return $this->body;
    }

    public function expectsJson(): bool
    {
        $accept = $this->header('accept', '');
        $requestedWith = $this->header('x-requested-with', '');

        return str_contains($accept, 'application/json')
            || str_contains($accept, 'text/json')
            || strtolower($requestedWith) === 'xmlhttprequest';
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function routeParams(): array
    {
        return $this->routeParams;
    }

    public function route(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    /**
     * @param array<string, string> $params
     */
    public function withRouteParams(array $params): static
    {
        return new static(
            $this->method,
            $this->path,
            $this->query,
            $this->request,
            $this->cookies,
            $this->files,
            $this->server,
            $this->headers,
            $this->body,
            $params,
            $this->session,
        );
    }

    public function hasSession(): bool
    {
        return $this->session instanceof Session;
    }

    public function session(): Session
    {
        if (! $this->session instanceof Session) {
            throw new \RuntimeException('No active session is available on this request.');
        }

        return $this->session;
    }

    public function withSession(Session $session): static
    {
        return new static(
            $this->method,
            $this->path,
            $this->query,
            $this->request,
            $this->cookies,
            $this->files,
            $this->server,
            $this->headers,
            $this->body,
            $this->routeParams,
            $session,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return [...$this->query, ...$this->request];
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->request[$key] ?? $this->query[$key] ?? $default;
    }

    public function old(string $key, mixed $default = null, string $bag = 'default'): mixed
    {
        if (! $this->hasSession()) {
            return $default;
        }

        $old = $this->session()->get('_old_input', []);

        if (! is_array($old)) {
            return $default;
        }

        if (isset($old[$bag]) && is_array($old[$bag])) {
            return $old[$bag][$key] ?? $default;
        }

        if ($bag === 'default') {
            return $old[$key] ?? $default;
        }

        return $default;
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(string $bag = 'default'): array
    {
        if (! $this->hasSession()) {
            return [];
        }

        $errors = $this->session()->get('_errors', []);

        if (! is_array($errors)) {
            return [];
        }

        if (isset($errors[$bag]) && is_array($errors[$bag])) {
            return $errors[$bag];
        }

        if ($bag === 'default' && $this->looksLikeFlatErrors($errors)) {
            return $errors;
        }

        return [];
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function integer(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->input($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value) || is_int($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

            return $parsed ?? $default;
        }

        return $default;
    }

    /**
     * @param array<string, string|list<string>> $rules
     * @param array<string, string> $messages
     * @return array<string, mixed>
     */
    public function validate(array $rules, array $messages = [], string $bag = 'default'): array
    {
        $data = $this->all();
        $validated = [];
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;
            $ruleset = is_array($fieldRules) ? $fieldRules : explode('|', $fieldRules);
            $isPresent = array_key_exists($field, $data);
            $isNullable = in_array('nullable', $ruleset, true);
            $isEmpty = $value === null || $value === '' || (is_array($value) && count($value) === 0);

            if ($isEmpty) {
                if (in_array('required', $ruleset, true)) {
                    $errors[$field][] = $messages["{$field}.required"] ?? 'This field is required.';
                    continue;
                }

                if ($isNullable) {
                    $validated[$field] = null;
                }

                continue;
            }

            foreach ($ruleset as $rule) {
                [$ruleName, $parameters] = $this->parseRule($rule);

                if ($ruleName === 'string' && ! is_string($value)) {
                    $errors[$field][] = $messages["{$field}.string"] ?? 'This field must be a string.';
                }

                if ($ruleName === 'integer' && filter_var($value, FILTER_VALIDATE_INT) === false) {
                    $errors[$field][] = $messages["{$field}.integer"] ?? 'This field must be an integer.';
                }

                if ($ruleName === 'numeric' && ! is_numeric($value)) {
                    $errors[$field][] = $messages["{$field}.numeric"] ?? 'This field must be a number.';
                }

                if ($ruleName === 'boolean' && filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === null) {
                    $errors[$field][] = $messages["{$field}.boolean"] ?? 'This field must be a boolean.';
                }

                if ($ruleName === 'array' && ! is_array($value)) {
                    $errors[$field][] = $messages["{$field}.array"] ?? 'This field must be an array.';
                }

                if ($ruleName === 'email' && (! is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false)) {
                    $errors[$field][] = $messages["{$field}.email"] ?? 'This field must be a valid email address.';
                }

                if ($ruleName === 'url' && (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false)) {
                    $errors[$field][] = $messages["{$field}.url"] ?? 'This field must be a valid URL.';
                }

                if ($ruleName === 'date' && (! is_string($value) || strtotime($value) === false)) {
                    $errors[$field][] = $messages["{$field}.date"] ?? 'This field must be a valid date.';
                }

                if ($ruleName === 'min') {
                    $min = isset($parameters[0]) ? (float) $parameters[0] : 0;
                    $fail = match (true) {
                        is_array($value) => count($value) < $min,
                        is_numeric($value) => (float) $value < $min,
                        default => mb_strlen((string) $value) < $min,
                    };
                    if ($fail) {
                        $errors[$field][] = $messages["{$field}.min"] ?? match (true) {
                            is_array($value) => "This field must have at least {$parameters[0]} items.",
                            is_numeric($value) => "This field must be at least {$parameters[0]}.",
                            default => "This field must be at least {$parameters[0]} characters.",
                        };
                    }
                }

                if ($ruleName === 'max') {
                    $max = isset($parameters[0]) ? (float) $parameters[0] : PHP_FLOAT_MAX;
                    $fail = match (true) {
                        is_array($value) => count($value) > $max,
                        is_numeric($value) => (float) $value > $max,
                        default => mb_strlen((string) $value) > $max,
                    };
                    if ($fail) {
                        $errors[$field][] = $messages["{$field}.max"] ?? match (true) {
                            is_array($value) => "This field must not have more than {$parameters[0]} items.",
                            is_numeric($value) => "This field must not be greater than {$parameters[0]}.",
                            default => "This field must not exceed {$parameters[0]} characters.",
                        };
                    }
                }

                if ($ruleName === 'confirmed') {
                    $confirmKey = "{$field}_confirmation";
                    $confirmValue = $data[$confirmKey] ?? null;
                    if ($value !== $confirmValue) {
                        $errors[$field][] = $messages["{$field}.confirmed"] ?? 'This field confirmation does not match.';
                    }
                }

                if ($ruleName === 'same') {
                    $otherField = $parameters[0] ?? '';
                    $otherValue = $data[$otherField] ?? null;
                    if ($value !== $otherValue) {
                        $errors[$field][] = $messages["{$field}.same"] ?? "This field must match {$otherField}.";
                    }
                }

                if ($ruleName === 'exists' && ! $this->passesExistsRule($field, $value, $parameters)) {
                    $errors[$field][] = $messages["{$field}.exists"] ?? 'The selected value is invalid.';
                }

                if ($ruleName === 'unique' && ! $this->passesUniqueRule($field, $value, $parameters)) {
                    $errors[$field][] = $messages["{$field}.unique"] ?? 'This value has already been taken.';
                }
            }

            if (! isset($errors[$field]) && $isPresent) {
                $validated[$field] = $value;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors, 'The given data was invalid.', $this, $bag);
        }

        return $validated;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function parseRule(string $rule): array
    {
        $segments = explode(':', $rule, 2);
        $name = $segments[0];
        $parameters = isset($segments[1]) ? array_map('trim', explode(',', $segments[1])) : [];

        return [$name, $parameters];
    }

    /**
     * @param list<string> $parameters
     */
    private function passesExistsRule(string $field, mixed $value, array $parameters): bool
    {
        $table = $parameters[0] ?? null;
        $column = $parameters[1] ?? $field;

        if (! is_string($table) || $table === '') {
            throw new \InvalidArgumentException(sprintf('Validation rule [exists] for [%s] requires a table name.', $field));
        }

        return DB::table($table)->where($column, $value)->exists();
    }

    /**
     * @param list<string> $parameters
     */
    private function passesUniqueRule(string $field, mixed $value, array $parameters): bool
    {
        $table = $parameters[0] ?? null;
        $column = $parameters[1] ?? $field;
        $ignore = $this->resolveValidationParameter($parameters[2] ?? null);
        $idColumn = $parameters[3] ?? 'id';

        if (! is_string($table) || $table === '') {
            throw new \InvalidArgumentException(sprintf('Validation rule [unique] for [%s] requires a table name.', $field));
        }

        $query = DB::table($table)->where($column, $value);

        if ($ignore !== null && $ignore !== '') {
            $query->where((string) $idColumn, '!=', $ignore);
        }

        return ! $query->exists();
    }

    private function resolveValidationParameter(mixed $parameter): mixed
    {
        if (! is_string($parameter)) {
            return $parameter;
        }

        if (preg_match('/^\{\$([A-Za-z_][A-Za-z0-9_]*)\}$/', $parameter, $matches) === 1) {
            $key = $matches[1];

            return $this->route($key, $this->input($key));
        }

        return $parameter;
    }

    private function looksLikeFlatErrors(array $errors): bool
    {
        foreach ($errors as $field => $messages) {
            if (! is_string($field) || ! is_array($messages)) {
                return false;
            }
        }

        return true;
    }

    private function sanitizeRedirectTarget(?string $target, string $fallback): string
    {
        $fallback = trim($fallback) === '' ? '/' : $fallback;
        $normalizedFallback = str_starts_with($fallback, '/') ? $fallback : '/' . ltrim($fallback, '/');

        if (! is_string($target)) {
            return $normalizedFallback;
        }

        $target = trim($target);

        if ($target === '' || str_contains($target, "\r") || str_contains($target, "\n")) {
            return $normalizedFallback;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $target) === 1 || str_starts_with($target, '//')) {
            return $normalizedFallback;
        }

        if (! str_starts_with($target, '/')) {
            return $normalizedFallback;
        }

        return '/' . ltrim($target, '/');
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function headersFromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }

            if (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $name = strtolower(str_replace('_', '-', $key));
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}
