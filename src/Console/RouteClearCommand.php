<?php

declare(strict_types=1);

namespace Wayfinder\Console;

final class RouteClearCommand implements Command
{
    public function __construct(
        private readonly string $path,
    ) {
    }

    public function name(): string
    {
        return 'route:clear';
    }

    public function description(): string
    {
        return 'Clear the route cache file.';
    }

    public function handle(array $arguments = []): int
    {
        if (! file_exists($this->path)) {
            fwrite(STDOUT, "Route cache is already clear.\n");

            return 0;
        }

        if (! @unlink($this->path)) {
            fwrite(STDERR, sprintf("Unable to delete route cache [%s].\n", $this->path));

            return 1;
        }

        fwrite(STDOUT, "Route cache cleared.\n");

        return 0;
    }
}
