<?php

declare(strict_types=1);

use App\Controllers\HomeController;
use App\Controllers\HealthController;

$router->group([
    'middleware' => ['web'],
], static function ($router): void {
    $router->get('/', [HomeController::class, 'index'], 'home');
    $router->post('/contact', [HomeController::class, 'submit'], 'home.contact');
});

$router->get('/health', [HealthController::class, 'health'], 'health');
