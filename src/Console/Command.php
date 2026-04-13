<?php

declare(strict_types=1);

namespace Wayfinder\Console;

interface Command
{
    public function name(): string;

    public function description(): string;

    /**
     * @param list<string> $arguments
     */
    public function handle(array $arguments = []): int;
}
