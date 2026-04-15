<?php

declare(strict_types=1);

namespace Wayfinder\Support;

use Wayfinder\Contracts\EventDispatcher as EventDispatcherContract;

final class EventDispatcher implements EventDispatcherContract
{
    /**
     * @var array<string, list<callable>>
     */
    private array $listeners = [];

    public function listen(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function dispatch(string $event, array $payload = []): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener(...$payload);
        }
    }
}
