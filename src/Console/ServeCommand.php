<?php

declare(strict_types=1);

namespace Wayfinder\Console;

final class ServeCommand implements Command
{
    public function __construct(
        private readonly string $documentRoot,
        private readonly string $defaultHost = '127.0.0.1',
        private readonly int $defaultPort = 8000,
    ) {
    }

    public function name(): string
    {
        return 'serve';
    }

    public function description(): string
    {
        return 'Start the PHP built-in development server.';
    }

    /**
     * @param list<string> $arguments
     */
    public function handle(array $arguments = []): int
    {
        $host = $this->defaultHost;
        $port = $this->defaultPort;

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--host=')) {
                $host = substr($argument, 7);
            } elseif (str_starts_with($argument, '--port=')) {
                $port = (int) substr($argument, 7);
            }
        }

        if (! is_dir($this->documentRoot)) {
            fwrite(STDERR, sprintf("Document root [%s] does not exist.\n", $this->documentRoot));

            return 1;
        }

        $address = sprintf('%s:%d', $host, $port);

        fwrite(STDOUT, sprintf("Starting development server on http://%s\n", $address));
        fwrite(STDOUT, "Press Ctrl+C to stop.\n\n");

        $command = sprintf(
            '%s -S %s -t %s',
            escapeshellarg(\PHP_BINARY),
            $address,
            escapeshellarg($this->documentRoot),
        );

        passthru($command, $exitCode);

        return $exitCode;
    }
}
