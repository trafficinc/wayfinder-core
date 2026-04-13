<?php

declare(strict_types=1);

namespace Wayfinder\Console;

use Wayfinder\Queue\Worker;

final class QueueWorkCommand implements Command
{
    public function __construct(
        private readonly Worker $worker,
    ) {
    }

    public function name(): string
    {
        return 'queue:work';
    }

    public function description(): string
    {
        return 'Process the next queued job.';
    }

    public function handle(array $arguments = []): int
    {
        $result = $this->worker->runNext();

        if ($result['status'] === 'empty') {
            fwrite(STDOUT, "No queued jobs available.\n");

            return 0;
        }

        if ($result['status'] === 'failed') {
            fwrite(STDERR, sprintf(
                "Queued job failed: %s\n",
                $result['error'] ?? 'Unknown error',
            ));

            return 1;
        }

        fwrite(STDOUT, "Processed one queued job.\n");

        return 0;
    }
}
