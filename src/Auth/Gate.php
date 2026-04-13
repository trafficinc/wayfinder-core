<?php

declare(strict_types=1);

namespace Wayfinder\Auth;

use Wayfinder\Http\Request;

final class Gate
{
    /**
     * @var array<string, callable(?array<string, mixed>, Request, mixed...): bool>
     */
    private array $abilities = [];

    public function __construct(
        private readonly AuthManager $auth,
    ) {
    }

    /**
     * @param callable(?array<string, mixed>, Request, mixed...): bool $callback
     */
    public function define(string $ability, callable $callback): self
    {
        $this->abilities[$ability] = $callback;

        return $this;
    }

    public function allows(string $ability, Request $request, mixed ...$arguments): bool
    {
        if (! isset($this->abilities[$ability])) {
            return false;
        }

        $user = $this->auth->user();

        return (bool) ($this->abilities[$ability])($user, $request, ...$arguments);
    }

    public function denies(string $ability, Request $request, mixed ...$arguments): bool
    {
        return ! $this->allows($ability, $request, ...$arguments);
    }
}
