<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Wayfinder\Support\EventDispatcher;
use Wayfinder\Support\Events;

final class EventsTest extends TestCase
{
    protected function tearDown(): void
    {
        Events::clearDispatcher();
    }

    public function test_dispatch_invokes_registered_listener_with_variadic_helper_payload(): void
    {
        $dispatcher = new EventDispatcher();
        Events::setDispatcher($dispatcher);

        $captured = [];

        \listen('order.created', static function (array $order, string $source) use (&$captured): void {
            $captured = [$order, $source];
        });

        \event('order.created', ['id' => 42], 'checkout');

        self::assertSame([['id' => 42], 'checkout'], $captured);
    }

    public function test_dispatcher_can_be_used_directly_through_registry(): void
    {
        $dispatcher = new EventDispatcher();
        Events::setDispatcher($dispatcher);

        $called = false;
        $dispatcher->listen('rfq.submitted', static function (array $rfq) use (&$called): void {
            $called = $rfq['id'] === 9;
        });

        Events::dispatch('rfq.submitted', [['id' => 9]]);

        self::assertTrue($called);
    }

    public function test_dispatch_without_registered_dispatcher_throws_clear_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No event dispatcher has been registered.');

        \event('cart.submitted', ['id' => 1]);
    }
}
