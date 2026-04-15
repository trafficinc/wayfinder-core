<?php

declare(strict_types=1);

namespace Wayfinder\Support;

use RuntimeException;
use Wayfinder\Contracts\EventDispatcher;

final class Events
{
    private static ?EventDispatcher $dispatcher = null;

    private function __construct()
    {
    }

    public static function setDispatcher(EventDispatcher $dispatcher): void
    {
        self::$dispatcher = $dispatcher;
    }

    public static function clearDispatcher(): void
    {
        self::$dispatcher = null;
    }

    public static function dispatcher(): EventDispatcher
    {
        if (self::$dispatcher === null) {
            throw new RuntimeException('No event dispatcher has been registered.');
        }

        return self::$dispatcher;
    }

    public static function listen(string $event, callable $listener): void
    {
        self::dispatcher()->listen($event, $listener);
    }

    /**
     * @param array<int, mixed> $payload
     */
    public static function dispatch(string $event, array $payload = []): void
    {
        self::dispatcher()->dispatch($event, $payload);
    }
}
