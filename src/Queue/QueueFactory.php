<?php

declare(strict_types=1);

namespace Wayfinder\Queue;

use Wayfinder\Database\Database;

final class QueueFactory
{
    public function __construct(
        private readonly ?Database $database = null,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public function make(array $config): Queue
    {
        return match ($config['driver'] ?? 'null') {
            'file' => new FileQueue(
                (string) ($config['path'] ?? sys_get_temp_dir() . '/wayfinder-queue'),
                isset($config['failed_path']) ? (string) $config['failed_path'] : null,
            ),
            'database' => $this->makeDatabaseQueue($config),
            'redis' => $this->makeRedisQueue($config),
            default => new NullQueue(),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function makeDatabaseQueue(array $config): Queue
    {
        if ($this->database === null) {
            throw new \RuntimeException('Database queue driver requires a database connection.');
        }

        return new DatabaseQueue(
            $this->database,
            (string) ($config['table'] ?? 'jobs'),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function makeRedisQueue(array $config): Queue
    {
        if (! class_exists(\Redis::class)) {
            throw new \RuntimeException('Redis queue driver requires the ext-redis PHP extension.');
        }

        $redis = new \Redis();
        $connected = $redis->connect(
            (string) ($config['host'] ?? '127.0.0.1'),
            (int) ($config['port'] ?? 6379),
            (float) ($config['timeout'] ?? 1.5),
        );

        if ($connected !== true) {
            throw new \RuntimeException('Unable to connect to the Redis queue server.');
        }

        $password = $config['password'] ?? null;

        if (is_string($password) && $password !== '' && $redis->auth($password) !== true) {
            throw new \RuntimeException('Redis queue authentication failed.');
        }

        if (isset($config['database']) && $redis->select((int) $config['database']) !== true) {
            throw new \RuntimeException('Unable to select the configured Redis queue database.');
        }

        return new RedisQueue(new PhpRedisQueueStore(
            $redis,
            (string) ($config['prefix'] ?? 'wayfinder_queue'),
        ));
    }
}
