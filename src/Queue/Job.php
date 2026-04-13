<?php

declare(strict_types=1);

namespace Wayfinder\Queue;

interface Job
{
    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload = []): void;
}
