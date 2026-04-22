<?php

declare(strict_types=1);

use Wayfinder\Database\DB;
use Wayfinder\Database\Database;
use Wayfinder\Support\Events;

if (! function_exists('event')) {
    function event(string $event, mixed ...$payload): void
    {
        Events::dispatch($event, $payload);
    }
}

if (! function_exists('db')) {
    function db(?string $name = null): Database
    {
        return DB::connection($name);
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

        if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            return htmlspecialchars(
                (string) $value,
                ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE,
                'UTF-8'
            );
        }

        throw new \InvalidArgumentException(
            'e() cannot escape value of type ' . gettype($value)
        );
    }
}

if (! function_exists('attrs')) {
    /**
     * Render an associative array as an HTML attribute string.
     *
     * Requires e() to be defined for value escaping.
     *
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
                $parts = [];
                foreach ($value as $item) {
                    if ($item === null || $item === false) {
                        continue;
                    }
                    if (is_scalar($item) || (is_object($item) && method_exists($item, '__toString'))) {
                        $parts[] = e((string) $item);
                    }
                }
                if ($parts === []) {
                    continue; // skip attribute entirely if array resolved to nothing
                }
                $rendered[] = sprintf('%s="%s"', $attributeName, implode(' ', $parts));
                continue;
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


// Escape for inline JS contexts (json_encode is safer than htmlspecialchars here)
function js_escape(mixed $value): string
{
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
}

// Escape for use inside CSS <style> blocks or style attributes
function css_escape(string $value): string
{
    return preg_replace('/[^a-zA-Z0-9\-_]/', '', $value);
}

// URL encode a single query parameter value
function url_escape(string $value): string
{
    return rawurlencode($value);
}


// Build a URL with a query string from an array
function url_with_query(string $base, array $params): string
{
    $query = http_build_query(array_filter($params, fn($v) => $v !== null && $v !== ''));
    return $query !== '' ? $base . '?' . $query : $base;
}

// Check if a URL is safe to redirect to (prevents open redirect)
function is_safe_redirect(string $url, string $allowedHost): bool
{
    $parsed = parse_url($url);
    if (isset($parsed['host']) && $parsed['host'] !== $allowedHost) {
        return false;
    }
    return true;
}


// Truncate a string without breaking words
function str_truncate(string $value, int $limit, string $end = '...'): string
{
    if (mb_strlen($value) <= $limit) {
        return $value;
    }
    return rtrim(mb_substr($value, 0, $limit)) . $end;
}

// Convert a string to a URL-safe slug
function slugify(string $value, string $separator = '-'): string
{
    $value = mb_strtolower(trim($value));
    $value = preg_replace('/[^\w\s-]/u', '', $value);
    return preg_replace('/[\s_-]+/', $separator, $value);
}

// Safely get a nested array value without isset chains
function array_get(array $array, string $key, mixed $default = null): mixed
{
    $keys = explode('.', $key);
    foreach ($keys as $segment) {
        if (! is_array($array) || ! array_key_exists($segment, $array)) {
            return $default;
        }
        $array = $array[$segment];
    }
    return $array;
}

// Pluck a single key from an array of arrays/objects
function array_pluck(array $items, string $key): array
{
    return array_map(fn($item) => is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null), $items);
}

// Conditionally render a class string (similar to clsx/classnames in JS)
function class_names(mixed ...$args): string
{
    $classes = [];
    foreach ($args as $arg) {
        if (is_string($arg) && $arg !== '') {
            $classes[] = $arg;
        } elseif (is_array($arg)) {
            foreach ($arg as $class => $condition) {
                if (is_int($class) && is_string($condition)) {
                    $classes[] = $condition;
                } elseif ($condition) {
                    $classes[] = $class;
                }
            }
        }
    }
    return implode(' ', $classes);
}

// Render an HTML tag
function html_tag(string $tag, array $attributes = [], ?string $content = null): string
{
    $void = in_array($tag, ['br','hr','img','input','link','meta','area','base','col','embed','param','source','track','wbr'], true);
    $attrString = attrs($attributes);
    $open = $attrString !== '' ? "<{$tag} {$attrString}>" : "<{$tag}>";
    if ($void) {
        return $open;
    }
    return $open . ($content !== null ? e($content) : '') . "</{$tag}>";
}
