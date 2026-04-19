<?php

declare(strict_types=1);

namespace Wayfinder\Console;

use Wayfinder\Queue\Queue;

final class QueueStatusCommand implements Command
{
    public function __construct(
        private readonly Queue $queue,
    ) {
    }

    public function name(): string
    {
        return 'queue:status';
    }

    public function description(): string
    {
        return 'Show pending, processing, and failed queue counts.';
    }

    public function handle(array $arguments = []): int
    {
        fwrite(STDOUT, sprintf(
            "Pending: %d\nProcessing: %d\nFailed: %d\n",
            $this->queue->size(),
            $this->queue->processingSize(),
            $this->queue->failedSize(),
        ));

        return 0;
    }
}
