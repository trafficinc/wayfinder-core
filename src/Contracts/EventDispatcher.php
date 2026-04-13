<?php

declare(strict_types=1);

namespace Wayfinder\Contracts;

interface EventDispatcher
{
    /**
     * @param array<int|string, mixed> $payload
     */
    public function dispatch(string $event, array $payload = []): void;
}
