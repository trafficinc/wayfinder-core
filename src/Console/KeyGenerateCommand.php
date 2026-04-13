<?php

declare(strict_types=1);

namespace Wayfinder\Console;

final class KeyGenerateCommand implements Command
{
    public function __construct(
        private readonly string $envPath,
    ) {
    }

    public function name(): string
    {
        return 'key:generate';
    }

    public function description(): string
    {
        return 'Generate and write a new APP_KEY value.';
    }

    public function handle(array $arguments = []): int
    {
        $key = 'base64:' . base64_encode(random_bytes(32));
        $contents = is_file($this->envPath) ? file_get_contents($this->envPath) : '';

        if ($contents === false) {
            fwrite(STDERR, sprintf("Unable to read env file [%s].\n", $this->envPath));

            return 1;
        }

        $updated = $this->replaceOrAppendKey(is_string($contents) ? $contents : '', $key);

        if (file_put_contents($this->envPath, $updated) === false) {
            fwrite(STDERR, sprintf("Unable to write env file [%s].\n", $this->envPath));

            return 1;
        }

        fwrite(STDOUT, sprintf("Application key set successfully: %s\n", $this->envPath));

        return 0;
    }

    private function replaceOrAppendKey(string $contents, string $key): string
    {
        $line = 'APP_KEY=' . $key;

        if (preg_match('/^APP_KEY=.*/m', $contents) === 1) {
            return (string) preg_replace('/^APP_KEY=.*/m', $line, $contents, 1);
        }

        $trimmed = rtrim($contents);

        if ($trimmed === '') {
            return $line . "\n";
        }

        return $trimmed . "\n" . $line . "\n";
    }
}
