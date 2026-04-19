<?php

declare(strict_types=1);

namespace App\Controllers;

use Wayfinder\Http\Request;
use Wayfinder\Http\Response;

final class HealthController
{
    public function health(Request $request): Response
    {
        return Response::text(
            "Stackmint app is running.\n"
            . sprintf("Method: %s\n", $request->method())
            . sprintf("Path: %s\n", $request->path())
        );
    }
}
