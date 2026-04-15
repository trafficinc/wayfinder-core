<?php

declare(strict_types=1);

namespace Wayfinder\Contracts;

interface EventDispatcher
{
    public function listen(string $event, callable $listener): void;

    /**
     * @param array<int|string, mixed> $payload
     */
    public function dispatch(string $event, array $payload = []): void;
}
