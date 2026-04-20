<?php

declare(strict_types=1);

namespace Wayfinder\Database;

use Wayfinder\Database\Concerns\HasAttributes;

abstract class DataTransferObject
{
    use HasAttributes;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): static
    {
        return new static($row);
    }
}
