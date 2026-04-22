<?php

declare(strict_types=1);

namespace Wayfinder\Queue;

use Wayfinder\Console\Application;
use Wayfinder\Console\QueueRecoverCommand;
use Wayfinder\Console\QueueStatusCommand;
use Wayfinder\Console\QueueWorkCommand;
use Wayfinder\Database\Database;
use Wayfinder\Database\DatabaseManager;
use Wayfinder\Logging\Logger;
use Wayfinder\Support\Config;
use Wayfinder\Support\Container;

final class QueueBootstrapper
{
    public static function register(Container $container, Config $config): void
    {
        $databaseConfig = $config->get('database', []);
        $databaseManager = new DatabaseManager(is_array($databaseConfig) ? $databaseConfig : []);

        $container->singleton(QueueFactory::class, static fn (Container $container): QueueFactory => new QueueFactory(
            $databaseManager->hasConnections() ? $container->get(Database::class) : null,
        ));
        $container->singleton(Queue::class, static fn (Container $container): Queue => (static function () use ($config, $container): Queue {
            $default = (string) $config->get('queue.default', 'file');
            $connection = $config->get("queue.connections.{$default}", []);

            return $container->get(QueueFactory::class)->make(is_array($connection) ? $connection : []);
        })());
        $container->singleton(JobDispatcher::class, static fn (Container $container): JobDispatcher => new JobDispatcher(
            $container->get(Queue::class),
            $container,
            $container->get(Logger::class),
            (string) $config->get('queue.default', 'file') === 'sync',
        ));
        $container->singleton(Worker::class, static fn (Container $container): Worker => new Worker(
            $container->get(Queue::class),
            $container,
            $container->get(Logger::class),
            (int) $config->get('queue.max_attempts', 3),
        ));
    }

    public static function registerCommands(Application $application, Container $container): Application
    {
        return $application
            ->add(new QueueWorkCommand($container->get(Worker::class)))
            ->add(new QueueRecoverCommand($container->get(Queue::class)))
            ->add(new QueueStatusCommand($container->get(Queue::class)));
    }
}
