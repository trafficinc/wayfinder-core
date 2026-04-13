<?php

declare(strict_types=1);

namespace Wayfinder\Contracts;

interface Container
{
    public function has(string $id): bool;

    public function get(string $id): mixed;
}
