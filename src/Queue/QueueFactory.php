<?php

declare(strict_types=1);

namespace Wayfinder\Queue;

final class QueueFactory
{
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
            default => new NullQueue(),
        };
    }
}
