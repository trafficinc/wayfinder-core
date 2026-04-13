<?php

declare(strict_types=1);

namespace Wayfinder\Session;

final class SessionManager
{
    private ?Session $current = null;

    public function setCurrent(Session $session): void
    {
        $this->current = $session;
    }

    public function current(): Session
    {
        if ($this->current === null) {
            throw new \RuntimeException('No active session is available for the current request.');
        }

        return $this->current;
    }

    public function clearCurrent(): void
    {
        $this->current = null;
    }
}
