<?php

declare(strict_types=1);

namespace Wayfinder\Session;

interface SessionStore
{
    public function load(string $id): Session;

    public function save(Session $session): void;

    public function delete(string $id): void;
}
