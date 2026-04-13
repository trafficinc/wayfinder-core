<?php

declare(strict_types=1);

namespace Wayfinder\Module;

use Wayfinder\Routing\Router;
use Wayfinder\Support\Config;
use Wayfinder\Support\Container;

abstract class ServiceProvider
{
    public function register(Container $container, Config $config, Module $module): void
    {
    }

    public function boot(Container $container, Router $router, Config $config, Module $module): void
    {
    }
}
