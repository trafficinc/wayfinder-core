<?php

declare(strict_types=1);

namespace Wayfinder\Queue;

use Wayfinder\Database\Database;

final class DatabaseQueue implements Queue
{
    public function __construct(
        private readonly Database $database,
        private readonly string $table = 'jobs',
    ) {
    }

    public function push(string $job, array $payload = []): void
    {
        $this->database->insert($this->table, [
            'job' => $job,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'status' => 'pending',
            'queued_at' => date('c'),
            'processing_started_at' => null,
            'failed_at' => null,
            'error' => null,
        ]);
    }

    public function pop(): ?array
    {
        return $this->database->transaction(function (): ?array {
            $row = $this->database
                ->table($this->table)
                ->select(['id', 'job', 'payload', 'attempts'])
                ->where('status', 'pending')
                ->orderBy('id')
                ->first();

            if ($row === false) {
                return null;
            }

            $attempts = ((int) ($row['attempts'] ?? 0)) + 1;
            $updated = $this->database
                ->table($this->table)
                ->prepareUpdate([
                    'status' => 'processing',
                    'attempts' => $attempts,
                    'processing_started_at' => date('c'),
                    'failed_at' => null,
                    'error' => null,
                ])
                ->where('id', (int) $row['id'])
                ->where('status', 'pending')
                ->execute();

            if ($updated !== 1) {
                return null;
            }

            return [
                'job' => (string) $row['job'],
                'payload' => $this->decodePayload($row['payload'] ?? '[]'),
                '__id' => (int) $row['id'],
                '__attempts' => $attempts,
            ];
        });
    }

    public function acknowledge(array $job): void
    {
        $id = $this->jobId($job);

        if ($id === null) {
            return;
        }

        $this->database->delete($this->table)->where('id', $id)->execute();
    }

    public function release(array $job): void
    {
        $id = $this->jobId($job);

        if ($id === null) {
            return;
        }

        $this->database
            ->table($this->table)
            ->prepareUpdate([
                'status' => 'pending',
                'processing_started_at' => null,
            ])
            ->where('id', $id)
            ->execute();
    }

    public function fail(array $job, \Throwable $throwable): void
    {
        $id = $this->jobId($job);

        if ($id === null) {
            return;
        }

        $this->database
            ->table($this->table)
            ->prepareUpdate([
                'status' => 'failed',
                'failed_at' => date('c'),
                'error' => $throwable->getMessage(),
            ])
            ->where('id', $id)
            ->execute();
    }

    public function recover(int $olderThanSeconds = 3600): int
    {
        $threshold = date('c', time() - $olderThanSeconds);

        return $this->database
            ->table($this->table)
            ->prepareUpdate([
                'status' => 'pending',
                'processing_started_at' => null,
            ])
            ->where('status', 'processing')
            ->where('processing_started_at', '<=', $threshold)
            ->execute();
    }

    public function size(): int
    {
        return $this->countByStatus('pending');
    }

    public function processingSize(): int
    {
        return $this->countByStatus('processing');
    }

    public function failedSize(): int
    {
        return $this->countByStatus('failed');
    }

    private function countByStatus(string $status): int
    {
        return $this->database
            ->table($this->table)
            ->where('status', $status)
            ->count();
    }

    private function jobId(array $job): ?int
    {
        $id = $job['__id'] ?? null;

        return is_int($id) ? $id : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(mixed $payload): array
    {
        if (! is_string($payload)) {
            return [];
        }

        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
