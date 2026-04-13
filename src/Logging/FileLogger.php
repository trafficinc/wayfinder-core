<?php

declare(strict_types=1);

namespace Wayfinder\Logging;

final class FileLogger implements Logger
{
    private const LEVELS = [
        'debug' => 100,
        'info' => 200,
        'warning' => 300,
        'error' => 400,
    ];

    public function __construct(
        private readonly string $path,
        private readonly string $level = 'debug',
    ) {
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $level = strtolower($level);

        if (! isset(self::LEVELS[$level])) {
            throw new \InvalidArgumentException(sprintf('Unsupported log level [%s].', $level));
        }

        if (self::LEVELS[$level] < self::LEVELS[$this->normalizeLevel($this->level)]) {
            return;
        }

        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($concurrentDirectory = $directory, 0777, true) && ! is_dir($concurrentDirectory)) {
            throw new \RuntimeException(sprintf('Unable to create log directory [%s].', $directory));
        }

        $line = sprintf(
            "[%s] %s: %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context === [] ? '' : ' ' . $this->encodeContext($context),
        );

        @file_put_contents($this->path, $line, FILE_APPEND);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function encodeContext(array $context): string
    {
        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '{}' : $encoded;
    }

    private function normalizeLevel(string $level): string
    {
        $level = strtolower($level);

        if (! isset(self::LEVELS[$level])) {
            throw new \InvalidArgumentException(sprintf('Unsupported log level [%s].', $level));
        }

        return $level;
    }
}
