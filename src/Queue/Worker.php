<?php

declare(strict_types=1);

namespace Wayfinder\Queue;

use Wayfinder\Contracts\Container;
use Wayfinder\Logging\Logger;

final class Worker
{
    public function __construct(
        private readonly Queue $queue,
        private readonly Container $container,
        private readonly Logger $logger,
        private readonly int $maxAttempts = 3,
    ) {
    }

    /**
     * @return array{status: 'empty'|'processed'|'released'|'failed', job?: string, error?: string, attempts?: int}
     */
    public function runNext(): array
    {
        $job = $this->queue->pop();

        if ($job === null) {
            return ['status' => 'empty'];
        }

        try {
            $instance = $this->container->get($job['job']);

            if (! $instance instanceof Job) {
                throw new \RuntimeException(sprintf('Queued job [%s] must implement %s.', $job['job'], Job::class));
            }

            $instance->handle($job['payload']);
            $this->queue->acknowledge($job);
            $this->logger->info('Queued job processed.', [
                'job'      => $job['job'],
                'attempts' => $job['__attempts'] ?? 1,
            ]);

            return [
                'status'   => 'processed',
                'job'      => $job['job'],
                'attempts' => $job['__attempts'] ?? 1,
            ];
        } catch (\Throwable $throwable) {
            $attempts = $job['__attempts'] ?? 1;

            if ($attempts < $this->maxAttempts) {
                $this->queue->release($job);
                $this->logger->warning('Queued job failed, will retry.', [
                    'job'        => $job['job'] ?? null,
                    'attempts'   => $attempts,
                    'maxAttempts' => $this->maxAttempts,
                    'exception'  => $throwable::class,
                    'message'    => $throwable->getMessage(),
                ]);

                return [
                    'status'   => 'released',
                    'job'      => is_string($job['job'] ?? null) ? $job['job'] : null,
                    'error'    => $throwable->getMessage(),
                    'attempts' => $attempts,
                ];
            }

            $this->queue->fail($job, $throwable);
            $this->logger->error('Queued job exhausted retries, moving to failed.', [
                'job'        => $job['job'] ?? null,
                'attempts'   => $attempts,
                'exception'  => $throwable::class,
                'message'    => $throwable->getMessage(),
            ]);

            return [
                'status'   => 'failed',
                'job'      => is_string($job['job'] ?? null) ? $job['job'] : null,
                'error'    => $throwable->getMessage(),
                'attempts' => $attempts,
            ];
        }
    }
}
