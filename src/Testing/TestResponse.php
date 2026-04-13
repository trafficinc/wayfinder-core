<?php

declare(strict_types=1);

namespace Wayfinder\Testing;

use PHPUnit\Framework\Assert;
use Wayfinder\Http\Response;

final class TestResponse
{
    public function __construct(
        private readonly Response $response,
    ) {
    }

    public function base(): Response
    {
        return $this->response;
    }

    public function status(): int
    {
        return $this->response->status();
    }

    public function content(): string
    {
        return $this->response->content();
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->response->headers();
    }

    public function assertStatus(int $expected): self
    {
        Assert::assertSame(
            $expected,
            $this->status(),
            sprintf('Expected response status [%d], received [%d].', $expected, $this->status()),
        );

        return $this;
    }

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    public function assertRedirect(string $location): self
    {
        if (! in_array($this->status(), [301, 302, 303, 307, 308], true)) {
            throw new \RuntimeException(sprintf(
                'Expected redirect status, received [%d].',
                $this->status(),
            ));
        }

        return $this->assertHeader('Location', $location);
    }

    public function assertHeader(string $name, string $expected): self
    {
        $actual = $this->response->headers()[$name] ?? null;
        Assert::assertSame(
            $expected,
            $actual,
            sprintf('Expected header [%s] to equal [%s], received [%s].', $name, $expected, $actual ?? 'null'),
        );

        return $this;
    }

    public function assertSee(string $needle): self
    {
        Assert::assertStringContainsString($needle, $this->content(), sprintf('Unable to find [%s] in response content.', $needle));

        return $this;
    }

    /**
     * @param array<string, mixed> $fragment
     */
    public function assertJsonFragment(array $fragment): self
    {
        $decoded = $this->json();

        foreach ($fragment as $key => $value) {
            Assert::assertArrayHasKey($key, $decoded, sprintf('JSON fragment assertion failed for key [%s].', $key));
            Assert::assertSame($value, $decoded[$key], sprintf('JSON fragment assertion failed for key [%s].', $key));
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $decoded = json_decode($this->content(), true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('Response content is not valid JSON.');
        }

        return $decoded;
    }
}
