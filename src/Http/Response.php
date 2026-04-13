<?php

declare(strict_types=1);

namespace Wayfinder\Http;

use Wayfinder\Session\Session;

final class Response
{
    /**
     * @param array<string, string> $headers
     * @param list<Cookie> $cookies
     */
    public function __construct(
        private readonly string $content,
        private readonly int $status = 200,
        private readonly array $headers = [],
        private readonly array $cookies = [],
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public static function make(string $content, int $status = 200, array $headers = []): self
    {
        return new self($content, $status, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function text(string $content, int $status = 200, array $headers = []): self
    {
        return new self($content, $status, ['Content-Type' => 'text/plain; charset=utf-8', ...$headers]);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function html(string $content, int $status = 200, array $headers = []): self
    {
        return new self($content, $status, ['Content-Type' => 'text/html; charset=utf-8', ...$headers]);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new \RuntimeException('Unable to encode JSON response.');
        }

        return new self($encoded, $status, ['Content-Type' => 'application/json; charset=utf-8', ...$headers]);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function redirect(string $location, int $status = 302, array $headers = []): self
    {
        return new self('', $status, ['Location' => $location, ...$headers]);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function back(Request $request, int $status = 302, array $headers = []): self
    {
        return self::redirect($request->redirectTarget(), $status, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function notFound(string $content = 'Not Found', array $headers = []): self
    {
        return self::text($content, 404, $headers);
    }

    public function content(): string
    {
        return $this->content;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @return list<Cookie>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function header(string $name, string $value): self
    {
        return new self(
            $this->content,
            $this->status,
            [...$this->headers, $name => $value],
            $this->cookies,
        );
    }

    public function withCookie(Cookie $cookie): self
    {
        return new self(
            $this->content,
            $this->status,
            $this->headers,
            [...$this->cookies, $cookie],
        );
    }

    public function withFlash(Session $session, string $key, mixed $value): self
    {
        $session->flash($key, $value);

        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header(sprintf('%s: %s', $name, $value));
        }

        foreach ($this->cookies as $cookie) {
            $cookie->send();
        }

        echo $this->content;
    }
}
