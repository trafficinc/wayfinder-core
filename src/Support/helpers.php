<?php

declare(strict_types=1);

use Wayfinder\Support\Events;

if (! function_exists('event')) {
    function event(string $event, mixed ...$payload): void
    {
        Events::dispatch($event, $payload);
    }
}

if (! function_exists('listen')) {
    function listen(string $event, callable $listener): void
    {
        Events::listen($event, $listener);
    }
}

if (! function_exists('e')) {
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        }

        return htmlspecialchars('', ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('attrs')) {
    /**
     * @param array<string, mixed> $attributes
     */
    function attrs(array $attributes): string
    {
        $rendered = [];

        foreach ($attributes as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }

            $attributeName = (string) $name;

            if ($attributeName === '' || preg_match('/^[a-zA-Z_:][a-zA-Z0-9:_.-]*$/', $attributeName) !== 1) {
                continue;
            }

            if ($value === true) {
                $rendered[] = $attributeName;

                continue;
            }

            if (is_array($value)) {
                $value = implode(' ', array_values(array_filter(array_map(static function (mixed $item): ?string {
                    if ($item === null || $item === false) {
                        return null;
                    }

                    if (is_scalar($item)) {
                        return (string) $item;
                    }

                    if (is_object($item) && method_exists($item, '__toString')) {
                        return (string) $item;
                    }

                    return null;
                }, $value))));
            }

            if (! is_scalar($value) && ! (is_object($value) && method_exists($value, '__toString'))) {
                continue;
            }

            $rendered[] = sprintf('%s="%s"', $attributeName, e($value));
        }

        return implode(' ', $rendered);
    }
}

if (! function_exists('checked')) {
    function checked(mixed $current, mixed $expected = true, bool $strict = false): string
    {
        return selected_attribute('checked', $current, $expected, $strict);
    }
}

if (! function_exists('selected')) {
    function selected(mixed $current, mixed $expected = true, bool $strict = false): string
    {
        return selected_attribute('selected', $current, $expected, $strict);
    }
}

if (! function_exists('disabled')) {
    function disabled(bool $condition = true): string
    {
        return $condition ? 'disabled' : '';
    }
}

if (! function_exists('selected_attribute')) {
    function selected_attribute(string $attribute, mixed $current, mixed $expected = true, bool $strict = false): string
    {
        $matches = false;

        if (is_array($current)) {
            foreach ($current as $value) {
                if (($strict && $value === $expected) || (! $strict && $value == $expected)) {
                    $matches = true;

                    break;
                }
            }
        } else {
            $matches = $strict ? $current === $expected : $current == $expected;
        }

        return $matches ? $attribute : '';
    }
}
