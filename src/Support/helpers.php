<?php

declare(strict_types=1);

use Wayfinder\Support\Events;

if (! function_exists('event')) {
    function event(string $event, mixed ...$payload): void
    {
        Events::dispatch($event, $payload);
    }
}

if (! function_exists('listen')) {
    function listen(string $event, callable $listener): void
    {
        Events::listen($event, $listener);
    }
}
