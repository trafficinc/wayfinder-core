<?php

declare(strict_types=1);

namespace Wayfinder\Auth;

use Wayfinder\Database\DB;
use Wayfinder\Session\SessionManager;

final class AuthManager
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly string $sessionKey = 'auth.user_id',
        private readonly string $table = 'users',
        private readonly string $primaryKey = 'id',
    ) {
    }

    public function check(): bool
    {
        return $this->id() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function id(): int|string|null
    {
        return $this->sessions->current()->get($this->sessionKey);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        $id = $this->id();

        if ($id === null) {
            return null;
        }

        $user = DB::table($this->table)
            ->where($this->primaryKey, $id)
            ->first();

        if (! is_array($user)) {
            return null;
        }

        unset($user['password']);

        return $user;
    }

    public function login(int|string $id): void
    {
        $session = $this->sessions->current();
        $session->regenerate();
        $session->put($this->sessionKey, $id);
    }

    public function logout(): void
    {
        $session = $this->sessions->current();
        $session->flush();
        $session->regenerate();
    }
}
