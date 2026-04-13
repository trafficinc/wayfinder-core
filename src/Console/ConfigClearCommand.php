<?php

declare(strict_types=1);

namespace Wayfinder\Console;

final class ConfigClearCommand implements Command
{
    public function __construct(
        private readonly string $path,
    ) {
    }

    public function name(): string
    {
        return 'config:clear';
    }

    public function description(): string
    {
        return 'Clear the config cache file.';
    }

    public function handle(array $arguments = []): int
    {
        if (! file_exists($this->path)) {
            fwrite(STDOUT, "Config cache is already clear.\n");

            return 0;
        }

        if (! @unlink($this->path)) {
            fwrite(STDERR, sprintf("Unable to delete config cache [%s].\n", $this->path));

            return 1;
        }

        fwrite(STDOUT, "Config cache cleared.\n");

        return 0;
    }
}
