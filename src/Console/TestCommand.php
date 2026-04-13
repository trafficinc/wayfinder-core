<?php

declare(strict_types=1);

namespace Wayfinder\Console;

final class TestCommand implements Command
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
    }

    public function name(): string
    {
        return 'test';
    }

    public function description(): string
    {
        return 'Run the PHPUnit test suite.';
    }

    /**
     * @param list<string> $arguments
     */
    public function handle(array $arguments = []): int
    {
        $binary = rtrim($this->projectRoot, '/') . '/vendor/bin/phpunit';

        if (! is_file($binary)) {
            fwrite(STDERR, "PHPUnit not found. Run: composer require --dev phpunit/phpunit\n");

            return 1;
        }

        $command = escapeshellarg($binary);

        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        passthru($command, $exitCode);

        return $exitCode;
    }
}
