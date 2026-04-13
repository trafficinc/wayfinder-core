<?php

declare(strict_types=1);

namespace Wayfinder\Session;

final class Session
{
    private const FLASH_OLD_KEY = '_flash.old';
    private const FLASH_NEW_KEY = '_flash.new';

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        private string $id,
        private array $attributes = [],
        private bool $exists = false,
        private bool $dirty = false,
        private ?string $previousId = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    public function markAsExisting(): void
    {
        $this->exists = true;
    }

    public function syncAfterSave(): void
    {
        $this->exists = true;
        $this->dirty = false;
        $this->previousId = null;
    }

    public function all(): array
    {
        return $this->attributes;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);

        return $value;
    }

    public function put(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
        $this->dirty = true;
    }

    public function forget(string $key): void
    {
        if (! array_key_exists($key, $this->attributes)) {
            return;
        }

        unset($this->attributes[$key]);
        $this->dirty = true;
    }

    public function flush(): void
    {
        $this->attributes = [];
        $this->dirty = true;
    }

    public function flash(string $key, mixed $value): void
    {
        $this->put($key, $value);

        $new = $this->flashKeys(self::FLASH_NEW_KEY);

        if (! in_array($key, $new, true)) {
            $new[] = $key;
            $this->attributes[self::FLASH_NEW_KEY] = $new;
            $this->dirty = true;
        }
    }

    public function ageFlashData(): void
    {
        $oldKeys = $this->flashKeys(self::FLASH_OLD_KEY);
        $newKeys = $this->flashKeys(self::FLASH_NEW_KEY);
        $changed = false;

        foreach ($oldKeys as $key) {
            if (array_key_exists($key, $this->attributes)) {
                unset($this->attributes[$key]);
                $changed = true;
            }
        }

        $hadFlashMetadata = array_key_exists(self::FLASH_OLD_KEY, $this->attributes)
            || array_key_exists(self::FLASH_NEW_KEY, $this->attributes);

        if ($hadFlashMetadata || $newKeys !== []) {
            if (($this->attributes[self::FLASH_OLD_KEY] ?? null) !== $newKeys) {
                $changed = true;
            }

            if (($this->attributes[self::FLASH_NEW_KEY] ?? null) !== []) {
                $changed = true;
            }

            $this->attributes[self::FLASH_OLD_KEY] = $newKeys;
            $this->attributes[self::FLASH_NEW_KEY] = [];
        }

        if ($changed) {
            $this->dirty = true;
        }
    }

    public function regenerate(bool $destroy = true): void
    {
        if ($destroy) {
            $this->previousId = $this->id;
        }

        $this->id = bin2hex(random_bytes(20));
        $this->exists = false;
        $this->dirty = true;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    public function previousId(): ?string
    {
        return $this->previousId;
    }

    /**
     * @return list<string>
     */
    private function flashKeys(string $key): array
    {
        $value = $this->attributes[$key] ?? [];

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
