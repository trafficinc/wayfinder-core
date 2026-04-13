<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Console;

use PHPUnit\Framework\TestCase;
use Wayfinder\Console\Application;
use Wayfinder\Console\Command;

final class ApplicationTest extends TestCase
{
    // -------------------------------------------------------------------------
    // --version / -V
    // -------------------------------------------------------------------------

    public function testVersionFlagReturnsZero(): void
    {
        ['code' => $code] = $this->execute(['wayfinder', '--version']);

        self::assertSame(0, $code);
    }

    public function testVersionFlagOutputsVersionString(): void
    {
        ['stdout' => $out] = $this->execute(['wayfinder', '--version']);

        self::assertStringContainsString('1.2.3', $out);
        self::assertStringContainsString('Wayfinder', $out);
    }

    public function testShortVersionFlagWorks(): void
    {
        ['code' => $code, 'stdout' => $out] = $this->execute(['wayfinder', '-V']);

        self::assertSame(0, $code);
        self::assertStringContainsString('1.2.3', $out);
    }

    // -------------------------------------------------------------------------
    // list command (default)
    // -------------------------------------------------------------------------

    public function testListCommandReturnsZero(): void
    {
        ['code' => $code] = $this->execute(['wayfinder', 'list']);

        self::assertSame(0, $code);
    }

    public function testListOutputsRegisteredCommands(): void
    {
        ['stdout' => $out] = $this->execute(['wayfinder', 'list'], commands: [new FakeCommand('greet', 'Says hello')]);

        self::assertStringContainsString('greet', $out);
        self::assertStringContainsString('Says hello', $out);
    }

    public function testDefaultCommandIsListWhenNoCommandGiven(): void
    {
        ['code' => $code, 'stdout' => $out] = $this->execute(['wayfinder'], commands: [new FakeCommand('demo', 'A demo command')]);

        self::assertSame(0, $code);
        self::assertStringContainsString('demo', $out);
    }

    // -------------------------------------------------------------------------
    // Unknown command
    // -------------------------------------------------------------------------

    public function testUnknownCommandReturnsOne(): void
    {
        ['code' => $code] = $this->execute(['wayfinder', 'no:such:command']);

        self::assertSame(1, $code);
    }

    public function testUnknownCommandWritesToStderr(): void
    {
        ['stderr' => $err] = $this->execute(['wayfinder', 'phantom']);

        self::assertStringContainsString('phantom', $err);
    }

    // -------------------------------------------------------------------------
    // Registered command is dispatched
    // -------------------------------------------------------------------------

    public function testRegisteredCommandIsInvoked(): void
    {
        $command = new FakeCommand('ping', 'Ping test');
        $this->execute(['wayfinder', 'ping'], commands: [$command]);

        self::assertTrue($command->wasHandled);
    }

    public function testRegisteredCommandReceivesArguments(): void
    {
        $command = new FakeCommand('echo', 'Echo args');
        $this->execute(['wayfinder', 'echo', '--flag', 'value'], commands: [$command]);

        self::assertSame(['--flag', 'value'], $command->lastArguments);
    }

    public function testRegisteredCommandExitCodeIsReturned(): void
    {
        $command = new FakeCommand('failing', 'Always fails', exitCode: 2);
        ['code' => $code] = $this->execute(['wayfinder', 'failing'], commands: [$command]);

        self::assertSame(2, $code);
    }

    // -------------------------------------------------------------------------
    // Exception in handle() returns 1
    // -------------------------------------------------------------------------

    public function testExceptionInHandleReturnsOne(): void
    {
        ['code' => $code] = $this->execute(['wayfinder', 'throws'], commands: [new ThrowingCommand()]);

        self::assertSame(1, $code);
    }

    public function testExceptionMessageWrittenToStderr(): void
    {
        ['stderr' => $err] = $this->execute(['wayfinder', 'throws'], commands: [new ThrowingCommand()]);

        self::assertStringContainsString('Command exploded', $err);
    }

    // -------------------------------------------------------------------------
    // add() returns self (fluent)
    // -------------------------------------------------------------------------

    public function testAddReturnsSelf(): void
    {
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        $app    = new Application('1.2.3', $stdout, $stderr);

        $result = $app->add(new FakeCommand('a', 'A'));

        fclose($stdout);
        fclose($stderr);

        self::assertSame($app, $result);
    }

    // -------------------------------------------------------------------------
    // Multiple commands registered
    // -------------------------------------------------------------------------

    public function testMultipleCommandsCanBeRegistered(): void
    {
        ['stdout' => $out] = $this->execute(['wayfinder', 'list'], commands: [
            new FakeCommand('cmd1', 'First'),
            new FakeCommand('cmd2', 'Second'),
            new FakeCommand('cmd3', 'Third'),
        ]);

        self::assertStringContainsString('cmd1', $out);
        self::assertStringContainsString('cmd2', $out);
        self::assertStringContainsString('cmd3', $out);
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    /**
     * Build an Application with in-memory streams, register any extra commands,
     * run the given argv, and return the exit code plus the captured output.
     *
     * @param list<string>  $argv
     * @param list<Command> $commands
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function execute(array $argv, array $commands = []): array
    {
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');

        $app = new Application('1.2.3', $stdout, $stderr);

        foreach ($commands as $command) {
            $app->add($command);
        }

        $code = $app->run($argv);

        rewind($stdout);
        rewind($stderr);
        $outText = (string) stream_get_contents($stdout);
        $errText = (string) stream_get_contents($stderr);

        fclose($stdout);
        fclose($stderr);

        return ['code' => $code, 'stdout' => $outText, 'stderr' => $errText];
    }
}

// ---------------------------------------------------------------------------
// Fixture commands
// ---------------------------------------------------------------------------

final class FakeCommand implements Command
{
    public bool $wasHandled = false;

    /** @var list<string> */
    public array $lastArguments = [];

    public function __construct(
        private readonly string $commandName,
        private readonly string $commandDescription,
        private readonly int $exitCode = 0,
    ) {
    }

    public function name(): string
    {
        return $this->commandName;
    }

    public function description(): string
    {
        return $this->commandDescription;
    }

    public function handle(array $arguments = []): int
    {
        $this->wasHandled    = true;
        $this->lastArguments = $arguments;

        return $this->exitCode;
    }
}

final class ThrowingCommand implements Command
{
    public function name(): string
    {
        return 'throws';
    }

    public function description(): string
    {
        return 'A command that always throws';
    }

    public function handle(array $arguments = []): int
    {
        throw new \RuntimeException('Command exploded');
    }
}
