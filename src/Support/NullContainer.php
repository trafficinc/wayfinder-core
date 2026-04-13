<?php

declare(strict_types=1);

namespace Wayfinder\Support;

use Wayfinder\Contracts\Container;

final class NullContainer implements Container
{
    public function has(string $id): bool
    {
        return false;
    }

    public function get(string $id): mixed
    {
        throw new \RuntimeException(sprintf('Nothing is bound for [%s].', $id));
    }
}
