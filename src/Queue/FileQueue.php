<?php

declare(strict_types=1);

namespace Wayfinder\Queue;

final class FileQueue implements Queue
{
    public function __construct(
        private readonly string $path,
        private readonly ?string $failedPath = null,
    ) {
        $this->ensureDirectories();
    }

    public function push(string $job, array $payload = []): void
    {
        $this->ensureDirectories();

        $file = sprintf('%s/%s_%s.job', $this->pendingPath(), $this->timestamp(), bin2hex(random_bytes(4)));
        file_put_contents($file, $this->encode($job, $payload, attempts: 0));
    }

    public function pop(): ?array
    {
        $this->ensureDirectories();

        $files = glob($this->pendingPath() . '/*.job') ?: [];
        sort($files);
        $file = $files[0] ?? null;

        if ($file === null) {
            return null;
        }

        $processingFile = $this->processingPath() . '/' . basename($file);

        if (! @rename($file, $processingFile)) {
            return null;
        }

        $contents = file_get_contents($processingFile);
        $data = is_string($contents) ? @unserialize($contents) : null;

        if (! is_array($data) || ! isset($data['job']) || ! is_string($data['job']) || ! isset($data['payload']) || ! is_array($data['payload'])) {
            $this->moveToFailed($processingFile, [
                'error' => 'Invalid queued job payload.',
                'failed_at' => date('c'),
            ]);

            return null;
        }

        // Increment the attempt counter and persist it so release() sees the
        // correct count if this attempt is abandoned and later recovered.
        $attempts = ((int) ($data['attempts'] ?? 0)) + 1;
        file_put_contents($processingFile, $this->encode($data['job'], $data['payload'], $attempts));

        return [
            'job'         => $data['job'],
            'payload'     => $data['payload'],
            '__file'      => $processingFile,
            '__attempts'  => $attempts,
        ];
    }

    public function acknowledge(array $job): void
    {
        $file = $job['__file'] ?? null;

        if (is_string($file) && is_file($file)) {
            unlink($file);
        }
    }

    public function release(array $job): void
    {
        $file = $job['__file'] ?? null;

        if (! is_string($file) || ! is_file($file)) {
            return;
        }

        // Use a fresh timestamp so the job goes to the back of the queue and
        // other pending jobs get a chance to run first.
        $newFile = sprintf('%s/%s_%s.job', $this->pendingPath(), $this->timestamp(), bin2hex(random_bytes(4)));

        if (! @rename($file, $newFile)) {
            // If rename fails, leave the job in processing so recover() can
            // pick it up later rather than losing it entirely.
        }
    }

    public function fail(array $job, \Throwable $throwable): void
    {
        $file = $job['__file'] ?? null;

        if (! is_string($file) || ! is_file($file)) {
            return;
        }

        $payload = [
            'job'        => $job['job'] ?? null,
            'payload'    => $job['payload'] ?? [],
            'attempts'   => $job['__attempts'] ?? null,
            'error'      => $throwable->getMessage(),
            'exception'  => $throwable::class,
            'failed_at'  => date('c'),
        ];

        $this->moveToFailed($file, $payload);
    }

    public function recover(int $olderThanSeconds = 3600): int
    {
        $this->ensureDirectories();

        $files     = glob($this->processingPath() . '/*.job') ?: [];
        $threshold = time() - $olderThanSeconds;
        $recovered = 0;

        foreach ($files as $file) {
            if (! is_file($file) || filemtime($file) > $threshold) {
                continue;
            }

            $newFile = sprintf('%s/%s_%s.job', $this->pendingPath(), $this->timestamp(), bin2hex(random_bytes(4)));

            if (@rename($file, $newFile)) {
                $recovered++;
            }
        }

        return $recovered;
    }

    public function size(): int
    {
        return count(glob($this->pendingPath() . '/*.job') ?: []);
    }

    public function processingSize(): int
    {
        return count(glob($this->processingPath() . '/*.job') ?: []);
    }

    public function failedSize(): int
    {
        return count(glob($this->failedPath() . '/*.failed') ?: []);
    }

    private function encode(string $job, array $payload, int $attempts): string
    {
        return serialize([
            'job'      => $job,
            'payload'  => $payload,
            'attempts' => $attempts,
        ]);
    }

    private function ensureDirectories(): void
    {
        foreach ([$this->pendingPath(), $this->processingPath(), $this->failedPath()] as $directory) {
            if (! is_dir($directory) && ! @mkdir($concurrentDirectory = $directory, 0777, true) && ! is_dir($concurrentDirectory)) {
                throw new \RuntimeException(sprintf('Unable to create queue directory [%s].', $directory));
            }
        }
    }

    private function pendingPath(): string
    {
        return rtrim($this->path, '/') . '/pending';
    }

    private function processingPath(): string
    {
        return rtrim($this->path, '/') . '/processing';
    }

    private function failedPath(): string
    {
        return $this->failedPath !== null
            ? rtrim($this->failedPath, '/')
            : rtrim($this->path, '/') . '/failed';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function moveToFailed(string $source, array $payload): void
    {
        $failedFile = sprintf('%s/%s_%s.failed', $this->failedPath(), $this->timestamp(), bin2hex(random_bytes(4)));
        file_put_contents($failedFile, serialize($payload));

        if (is_file($source)) {
            unlink($source);
        }
    }

    private function timestamp(): string
    {
        return sprintf('%.6F', microtime(true));
    }
}
