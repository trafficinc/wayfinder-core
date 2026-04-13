<?php

declare(strict_types=1);

namespace Wayfinder\Support;

use Wayfinder\Contracts\EventDispatcher;

final class NullEventDispatcher implements EventDispatcher
{
    public function dispatch(string $event, array $payload = []): void
    {
    }
}
