<?php

declare(strict_types=1);

namespace Wayfinder\Support;

final class Env
{
    public static function load(string $path): void
    {
        if (! is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            throw new \RuntimeException(sprintf('Unable to read environment file [%s].', $path));
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $trimmed, 2), 2, '');
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $value = self::normalizeValue(trim($value));

            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            putenv(sprintf('%s=%s', $name, $value));
        }
    }

    private static function normalizeValue(string $value): string
    {
        $length = strlen($value);

        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        return $value;
    }
}
