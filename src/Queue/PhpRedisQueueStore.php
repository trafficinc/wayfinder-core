<?php

declare(strict_types=1);

namespace Wayfinder\Queue;

final class PhpRedisQueueStore implements RedisQueueStore
{
    public function __construct(
        private readonly \Redis $redis,
        private readonly string $prefix = 'wayfinder_queue',
    ) {
    }

    public function push(string $job, array $payload = []): void
    {
        $id = (string) $this->redis->incr($this->idsKey());
        $this->redis->hMSet($this->jobKey($id), [
            'job' => $job,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'attempts' => '0',
            'status' => 'pending',
            'queued_at' => (string) time(),
            'processing_started_at' => '',
            'failed_at' => '',
            'error' => '',
            'exception' => '',
        ]);
        $this->redis->rPush($this->pendingKey(), $id);
    }

    public function pop(): ?array
    {
        $result = $this->redis->eval(
            <<<'LUA'
local pending = KEYS[1]
local processing = KEYS[2]
local jobPrefix = ARGV[1]
local now = ARGV[2]

local id = redis.call('LPOP', pending)
if not id then
    return nil
end

local jobKey = jobPrefix .. id
if redis.call('EXISTS', jobKey) == 0 then
    return {'__missing__'}
end

local attempts = redis.call('HINCRBY', jobKey, 'attempts', 1)
redis.call('HSET', jobKey, 'status', 'processing', 'processing_started_at', now)
redis.call('ZADD', processing, now, id)

local values = redis.call('HMGET', jobKey, 'job', 'payload')
return {id, values[1], values[2], tostring(attempts)}
LUA,
            [$this->pendingKey(), $this->processingKey(), $this->jobKeyPrefix(), (string) time()],
            2,
        );

        if (! is_array($result) || $result === []) {
            return null;
        }

        if (($result[0] ?? null) === '__missing__') {
            return $this->pop();
        }

        return [
            'job' => (string) ($result[1] ?? ''),
            'payload' => $this->decodePayload($result[2] ?? ''),
            '__id' => (string) ($result[0] ?? ''),
            '__attempts' => (int) ($result[3] ?? 1),
        ];
    }

    public function acknowledge(array $job): void
    {
        $id = $this->jobId($job);

        if ($id === null) {
            return;
        }

        $this->redis->multi();
        $this->redis->zRem($this->processingKey(), $id);
        $this->redis->del($this->jobKey($id));
        $this->redis->exec();
    }

    public function release(array $job): void
    {
        $id = $this->jobId($job);

        if ($id === null) {
            return;
        }

        $this->redis->multi();
        $this->redis->zRem($this->processingKey(), $id);
        $this->redis->hMSet($this->jobKey($id), [
            'status' => 'pending',
            'processing_started_at' => '',
        ]);
        $this->redis->rPush($this->pendingKey(), $id);
        $this->redis->exec();
    }

    public function fail(array $job, \Throwable $throwable): void
    {
        $id = $this->jobId($job);

        if ($id === null) {
            return;
        }

        $now = (string) time();
        $this->redis->multi();
        $this->redis->zRem($this->processingKey(), $id);
        $this->redis->hMSet($this->jobKey($id), [
            'status' => 'failed',
            'failed_at' => $now,
            'error' => $throwable->getMessage(),
            'exception' => $throwable::class,
        ]);
        $this->redis->zAdd($this->failedKey(), (int) $now, $id);
        $this->redis->exec();
    }

    public function recover(int $olderThanSeconds = 3600): int
    {
        $threshold = time() - $olderThanSeconds;
        $stale = $this->redis->zRangeByScore($this->processingKey(), '-inf', (string) $threshold);

        if (! is_array($stale) || $stale === []) {
            return 0;
        }

        $recovered = 0;

        foreach ($stale as $id) {
            if (! is_string($id) || $id === '') {
                continue;
            }

            $this->redis->multi();
            $this->redis->zRem($this->processingKey(), $id);
            $this->redis->hMSet($this->jobKey($id), [
                'status' => 'pending',
                'processing_started_at' => '',
            ]);
            $this->redis->rPush($this->pendingKey(), $id);
            $result = $this->redis->exec();

            if (is_array($result) && ((int) ($result[0] ?? 0)) > 0) {
                $recovered++;
            }
        }

        return $recovered;
    }

    public function size(): int
    {
        return $this->redis->lLen($this->pendingKey());
    }

    public function processingSize(): int
    {
        return $this->redis->zCard($this->processingKey());
    }

    public function failedSize(): int
    {
        return $this->redis->zCard($this->failedKey());
    }

    private function pendingKey(): string
    {
        return $this->prefix . ':pending';
    }

    private function processingKey(): string
    {
        return $this->prefix . ':processing';
    }

    private function failedKey(): string
    {
        return $this->prefix . ':failed';
    }

    private function idsKey(): string
    {
        return $this->prefix . ':ids';
    }

    private function jobKeyPrefix(): string
    {
        return $this->prefix . ':job:';
    }

    private function jobKey(string $id): string
    {
        return $this->jobKeyPrefix() . $id;
    }

    private function jobId(array $job): ?string
    {
        $id = $job['__id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
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
