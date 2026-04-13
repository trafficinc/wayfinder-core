<?php

declare(strict_types=1);

namespace Wayfinder\Console;

final class Application
{
    /**
     * @var array<string, Command>
     */
    private array $commands = [];

    /** @var resource */
    private mixed $stdout;

    /** @var resource */
    private mixed $stderr;

    /**
     * @param resource|null $stdout Stream to write normal output to (defaults to STDOUT).
     * @param resource|null $stderr Stream to write error output to (defaults to STDERR).
     */
    public function __construct(
        private readonly string $version = 'dev',
        mixed $stdout = null,
        mixed $stderr = null,
    ) {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
    }

    public function add(Command $command): self
    {
        $this->commands[$command->name()] = $command;

        return $this;
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        if (in_array('--version', $argv, true) || in_array('-V', $argv, true)) {
            fwrite($this->stdout, sprintf("Wayfinder %s\n", $this->version));

            return 0;
        }

        $name = $argv[1] ?? 'list';
        $arguments = array_slice($argv, 2);

        if ($name === 'list') {
            $this->writeAvailableCommands();

            return 0;
        }

        $command = $this->commands[$name] ?? null;

        if ($command === null) {
            fwrite($this->stderr, sprintf("Command [%s] is not defined.\n", $name));
            $this->writeAvailableCommands();

            return 1;
        }

        try {
            return $command->handle($arguments);
        } catch (\Throwable $throwable) {
            fwrite($this->stderr, sprintf("Error: %s\n", $throwable->getMessage()));

            return 1;
        }
    }

    private function writeAvailableCommands(): void
    {
        fwrite($this->stdout, "Wayfinder CLI\n\n");
        fwrite($this->stdout, "Available commands:\n");

        foreach ($this->commands as $command) {
            fwrite($this->stdout, sprintf("  %-18s %s\n", $command->name(), $command->description()));
        }
    }
}
