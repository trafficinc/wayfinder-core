<?php

declare(strict_types=1);

namespace Wayfinder\Queue;

use Wayfinder\Contracts\Container;
use Wayfinder\Logging\Logger;

final class JobDispatcher
{
    public function __construct(
        private readonly Queue $queue,
        private readonly ?Container $container = null,
        private readonly ?Logger $logger = null,
        private readonly bool $sync = false,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $job, array $payload = []): void
    {
        if ($this->sync) {
            if ($this->container === null) {
                throw new \RuntimeException('Sync queue dispatch requires a container.');
            }

            $instance = $this->container->get($job);

            if (! $instance instanceof Job) {
                throw new \RuntimeException(sprintf('Queued job [%s] must implement %s.', $job, Job::class));
            }

            $instance->handle($payload);
            $this->logger?->info('Queued job processed synchronously.', [
                'job' => $job,
            ]);

            return;
        }

        $this->queue->push($job, $payload);
    }
}
