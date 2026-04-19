<?php

declare(strict_types=1);

namespace Wayfinder\Console;

use Wayfinder\Queue\Queue;

final class QueueRecoverCommand implements Command
{
    public function __construct(
        private readonly Queue $queue,
    ) {
    }

    public function name(): string
    {
        return 'queue:recover';
    }

    public function description(): string
    {
        return 'Recover stale processing jobs back to the pending queue.';
    }

    public function handle(array $arguments = []): int
    {
        $olderThanSeconds = isset($arguments[0]) && is_numeric($arguments[0]) ? max(0, (int) $arguments[0]) : 3600;
        $recovered = $this->queue->recover($olderThanSeconds);

        fwrite(STDOUT, sprintf("Recovered %d queued job(s).\n", $recovered));

        return 0;
    }
}
