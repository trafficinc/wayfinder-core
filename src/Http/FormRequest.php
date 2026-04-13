<?php

declare(strict_types=1);

namespace Wayfinder\Http;

use Wayfinder\Session\Session;

abstract class FormRequest extends Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $request
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $files
     * @param array<string, mixed> $server
     * @param array<string, string> $headers
     * @param array<string, string> $routeParams
     * @param Session|null $session
     * @param array<string, mixed> $validated
     */
    final public function __construct(
        string $method,
        string $path,
        array $query,
        array $request,
        array $cookies,
        array $files,
        array $server,
        array $headers,
        string $body,
        array $routeParams,
        ?Session $session,
        private readonly array $validated,
    ) {
        parent::__construct($method, $path, $query, $request, $cookies, $files, $server, $headers, $body, $routeParams, $session);
    }

    /**
     * @return array<string, string|list<string>>
     */
    abstract public function rules(): array;

    /**
     * Custom validation messages keyed as "field.rule".
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        return $this->validated;
    }

    public function validatedInput(string $key, mixed $default = null): mixed
    {
        return $this->validated[$key] ?? $default;
    }

    public static function fromRequest(Request $request): static
    {
        $instance = new static(
            $request->method(),
            $request->path(),
            $request->query(),
            $request->request(),
            $request->cookies(),
            $request->files(),
            $request->server(),
            $request->headers(),
            $request->body(),
            $request->routeParams(),
            $request->hasSession() ? $request->session() : null,
            [],
        );

        return new static(
            $instance->method(),
            $instance->path(),
            $instance->query(),
            $instance->request(),
            $instance->cookies(),
            $instance->files(),
            $instance->server(),
            $instance->headers(),
            $instance->body(),
            $instance->routeParams(),
            $instance->hasSession() ? $instance->session() : null,
            $instance->validate($instance->rules(), $instance->messages()),
        );
    }
}
