<?php

declare(strict_types=1);

namespace Wayfinder\Http;

use Wayfinder\Session\Session;

final class CsrfTokenManager
{
    public function __construct(
        private readonly string $sessionKey = '_csrf_token',
    ) {
    }

    public function token(Session $session): string
    {
        $token = $session->get($this->sessionKey);

        if (is_string($token) && $token !== '') {
            return $token;
        }

        $token = bin2hex(random_bytes(20));
        $session->put($this->sessionKey, $token);

        return $token;
    }

    public function matches(Session $session, ?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        $known = $session->get($this->sessionKey);

        return is_string($known) && hash_equals($known, $token);
    }
}
