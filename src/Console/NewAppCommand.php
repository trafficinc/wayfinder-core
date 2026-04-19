<?php

declare(strict_types=1);

namespace Wayfinder\Console;

final class NewAppCommand implements Command
{
    public function __construct(
        private readonly string $skeletonPath,
        private readonly string $workingDirectory,
    ) {
    }

    public function name(): string
    {
        return 'new';
    }

    public function description(): string
    {
        return 'Create a new Stackmint application from the default skeleton.';
    }

    public function handle(array $arguments = []): int
    {
        $name = trim($arguments[0] ?? '');

        if ($name === '') {
            fwrite(STDERR, "Usage: wayfinder new <directory>\n");

            return 1;
        }

        $source = rtrim($this->skeletonPath, '/');

        if (! is_dir($source)) {
            fwrite(STDERR, sprintf("Skeleton app path [%s] was not found.\n", $source));

            return 1;
        }

        $target = $this->resolveTargetPath($name);

        if (file_exists($target)) {
            fwrite(STDERR, sprintf("Target directory [%s] already exists.\n", $target));

            return 1;
        }

        if (! mkdir($target, 0777, true) && ! is_dir($target)) {
            fwrite(STDERR, sprintf("Unable to create target directory [%s].\n", $target));

            return 1;
        }

        $this->copyDirectory($source, $target);
        $this->rewriteComposerPackageName($target, basename($target));

        fwrite(STDOUT, sprintf("Created Stackmint app in [%s].\n", $target));
        fwrite(STDOUT, "Next steps:\n");
        fwrite(STDOUT, sprintf("  cd %s\n", basename($target)));
        fwrite(STDOUT, "  cp .env.example .env\n");
        fwrite(STDOUT, "  composer install\n");
        fwrite(STDOUT, "  php wayfinder key:generate\n");
        fwrite(STDOUT, "  php wayfinder migrate\n");
        fwrite(STDOUT, "  php -S localhost:8000 -t public\n");

        return 0;
    }

    private function resolveTargetPath(string $name): string
    {
        if (str_starts_with($name, '/')) {
            return rtrim($name, '/');
        }

        return rtrim($this->workingDirectory, '/') . '/' . trim($name, '/');
    }

    private function copyDirectory(string $source, string $target): void
    {
        $items = scandir($source);

        if ($items === false) {
            throw new \RuntimeException(sprintf('Unable to read skeleton directory [%s].', $source));
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $from = $source . '/' . $item;
            $to = $target . '/' . $item;

            if (is_dir($from)) {
                if (! mkdir($to, 0777, true) && ! is_dir($to)) {
                    throw new \RuntimeException(sprintf('Unable to create directory [%s].', $to));
                }

                $this->copyDirectory($from, $to);

                continue;
            }

            if (! copy($from, $to)) {
                throw new \RuntimeException(sprintf('Unable to copy [%s] to [%s].', $from, $to));
            }
        }
    }

    private function rewriteComposerPackageName(string $target, string $directoryName): void
    {
        $composerPath = $target . '/composer.json';

        if (! is_file($composerPath)) {
            return;
        }

        $contents = file_get_contents($composerPath);

        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read [%s].', $composerPath));
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException(sprintf('Unable to decode [%s].', $composerPath));
        }

        $decoded['name'] = 'wayfinder/' . $this->normalizePackageName($directoryName);

        $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false || file_put_contents($composerPath, $encoded . "\n") === false) {
            throw new \RuntimeException(sprintf('Unable to write [%s].', $composerPath));
        }
    }

    private function normalizePackageName(string $directoryName): string
    {
        $normalized = strtolower($directoryName);
        $normalized = preg_replace('/[^a-z0-9._-]+/', '-', $normalized) ?? $normalized;
        $normalized = trim($normalized, '-');

        return $normalized === '' ? 'stackmint' : $normalized;
    }
}
