<?php

declare(strict_types=1);

namespace Wayfinder\Http;

final class ValidationException extends \RuntimeException
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(
        private readonly array $errors,
        string $message = 'The given data was invalid.',
        private readonly ?Request $request = null,
        private readonly string $bag = 'default',
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function request(): ?Request
    {
        return $this->request;
    }

    public function bag(): string
    {
        return $this->bag;
    }
}
