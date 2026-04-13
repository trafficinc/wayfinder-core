<?php

declare(strict_types=1);

namespace Wayfinder\Console;

use Wayfinder\Routing\Router;
use Wayfinder\Support\Config;

final class RouteCacheCommand implements Command
{
    public function __construct(
        private readonly Router $router,
        private readonly Config $config,
        private readonly string $path,
    ) {
    }

    public function name(): string
    {
        return 'route:cache';
    }

    public function description(): string
    {
        return 'Cache the route manifest.';
    }

    public function handle(array $arguments = []): int
    {
        $environment = (string) $this->config->get('app.environment', 'local');

        if (in_array($environment, ['local', 'development'], true)) {
            throw new \RuntimeException(sprintf(
                'Route caching is disabled in the [%s] environment.',
                $environment,
            ));
        }

        $manifest = $this->router->cacheManifest();
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            fwrite(STDERR, sprintf("Unable to create route cache directory [%s].\n", $directory));

            return 1;
        }

        $payload = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($manifest, true) . ";\n";

        if (file_put_contents($this->path, $payload) === false) {
            fwrite(STDERR, sprintf("Unable to write route cache [%s].\n", $this->path));

            return 1;
        }

        fwrite(STDOUT, sprintf("Routes cached: %s\n", $this->path));

        return 0;
    }
}
