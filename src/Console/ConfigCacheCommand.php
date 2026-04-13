<?php

declare(strict_types=1);

namespace Wayfinder\Console;

use Wayfinder\Support\Config;

final class ConfigCacheCommand implements Command
{
    public function __construct(
        private readonly Config $config,
        private readonly string $path,
    ) {
    }

    public function name(): string
    {
        return 'config:cache';
    }

    public function description(): string
    {
        return 'Cache the resolved config array.';
    }

    public function handle(array $arguments = []): int
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            fwrite(STDERR, sprintf("Unable to create config cache directory [%s].\n", $directory));

            return 1;
        }

        $payload = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($this->config->all(), true) . ";\n";

        if (file_put_contents($this->path, $payload) === false) {
            fwrite(STDERR, sprintf("Unable to write config cache [%s].\n", $this->path));

            return 1;
        }

        fwrite(STDOUT, sprintf("Config cached: %s\n", $this->path));

        return 0;
    }
}
