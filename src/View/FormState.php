<?php

declare(strict_types=1);

namespace Wayfinder\View;

use Wayfinder\Http\Request;

final class FormState
{
    public function __construct(
        private readonly Request $request,
        private readonly ?string $csrfToken = null,
    ) {
    }

    public function csrfField(): string
    {
        if (! is_string($this->csrfToken) || $this->csrfToken === '') {
            return '';
        }

        return sprintf(
            '<input type="hidden" name="_token" value="%s">',
            htmlspecialchars($this->csrfToken, ENT_QUOTES, 'UTF-8'),
        );
    }

    public function old(string $key, mixed $default = null, string $bag = 'default'): mixed
    {
        return $this->request->old($key, $default, $bag);
    }

    public function error(string $key, string $bag = 'default'): ?string
    {
        $errors = $this->request->errors($bag);

        if (! isset($errors[$key][0]) || ! is_string($errors[$key][0])) {
            return null;
        }

        return $errors[$key][0];
    }
}
