<?php

declare(strict_types=1);

namespace Wayfinder\Console\Concerns;

trait GeneratesCode
{
    /**
     * @param list<string> $arguments
     * @return array{name: string, module: string|null, namespace: string|null}|null
     */
    protected function parseGeneratorInput(array $arguments): ?array
    {
        $name = null;
        $module = null;
        $namespace = null;

        foreach ($arguments as $argument) {
            if (! is_string($argument) || trim($argument) === '') {
                continue;
            }

            if (str_starts_with($argument, '--module=')) {
                $module = trim((string) substr($argument, 9));

                continue;
            }

            if (str_starts_with($argument, '--namespace=')) {
                $namespace = trim((string) substr($argument, 12));

                continue;
            }

            if ($name === null) {
                $name = $argument;
            }
        }

        if ($name === null || trim($name) === '') {
            return null;
        }

        return [
            'name' => $name,
            'module' => $module !== '' ? $module : null,
            'namespace' => $namespace !== '' ? $namespace : null,
        ];
    }

    protected function normalizeClassName(string $name, string $suffix = ''): string
    {
        $trimmed = trim($name, '\\/');
        $parts = preg_split('/[\/\\\\]+/', $trimmed) ?: [];
        $normalized = [];

        foreach ($parts as $part) {
            $segment = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $part);
            $segment = preg_replace('/[^A-Za-z0-9]+/', ' ', (string) $segment);
            $segment = str_replace(' ', '', ucwords(strtolower(trim((string) $segment))));

            if ($segment === '') {
                continue;
            }

            $normalized[] = $segment;
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException('Name must contain letters or numbers.');
        }

        $last = array_pop($normalized);

        if ($suffix !== '' && ! str_ends_with($last, $suffix)) {
            $last .= $suffix;
        }

        $normalized[] = $last;

        return implode('\\', $normalized);
    }

    protected function normalizeNamespace(string $namespace): string
    {
        $trimmed = trim($namespace, '\\/');

        if ($trimmed === '') {
            return '';
        }

        $parts = preg_split('/[\/\\\\]+/', $trimmed) ?: [];
        $normalized = [];

        foreach ($parts as $part) {
            $segment = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $part);
            $segment = preg_replace('/[^A-Za-z0-9]+/', ' ', (string) $segment);
            $segment = str_replace(' ', '', ucwords(strtolower(trim((string) $segment))));

            if ($segment !== '') {
                $normalized[] = $segment;
            }
        }

        return implode('\\', $normalized);
    }

    protected function ensureDirectoryExists(string $directory, string $label): bool
    {
        if (is_dir($directory)) {
            return true;
        }

        if (mkdir($directory, 0777, true) || is_dir($directory)) {
            return true;
        }

        fwrite(STDERR, sprintf("Unable to create %s directory [%s].\n", $label, $directory));

        return false;
    }
}
