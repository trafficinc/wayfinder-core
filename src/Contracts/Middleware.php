<?php

declare(strict_types=1);

namespace Wayfinder\Contracts;

use Wayfinder\Http\Request;
use Wayfinder\Http\Response;

interface Middleware
{
    /**
     * @param callable(Request): Response $next
     */
    public function handle(Request $request, callable $next): Response;
}
