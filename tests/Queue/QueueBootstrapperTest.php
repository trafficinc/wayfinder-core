<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Queue;

use PHPUnit\Framework\TestCase;
use Wayfinder\Console\Application;
use Wayfinder\Database\Database;
use Wayfinder\Logging\NullLogger;
use Wayfinder\Queue\FileQueue;
use Wayfinder\Queue\JobDispatcher;
use Wayfinder\Queue\Queue;
use Wayfinder\Queue\QueueBootstrapper;
use Wayfinder\Queue\Worker;
use Wayfinder\Support\Config;
use Wayfinder\Support\Container;
use Wayfinder\Tests\Concerns\UsesTempDirectory;

final class QueueBootstrapperTest extends TestCase
{
    use UsesTempDirectory;

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
        BootstrapperCountingJob::$handled = 0;
    }

    protected function tearDown(): void
    {
        $this->tearDownTempDirectory();
    }

    public function testRegisterBindsQueueDispatcherAndWorker(): void
    {
        $container = $this->makeContainer();

        QueueBootstrapper::register($container, $this->config());

        self::assertInstanceOf(Queue::class, $container->get(Queue::class));
        self::assertInstanceOf(JobDispatcher::class, $container->get(JobDispatcher::class));
        self::assertInstanceOf(Worker::class, $container->get(Worker::class));
    }

    public function testRegisterUsesConfiguredFileQueuePath(): void
    {
        $container = $this->makeContainer();

        QueueBootstrapper::register($container, $this->config());

        $queue = $container->get(Queue::class);
        self::assertInstanceOf(FileQueue::class, $queue);

        $container->get(JobDispatcher::class)->dispatch(BootstrapperCountingJob::class);

        self::assertSame(1, $queue->size());
        self::assertDirectoryExists($this->tempDir . '/queue/pending');
    }

    public function testRegisterRunsJobsImmediatelyForSyncDriver(): void
    {
        $container = $this->makeContainer();

        QueueBootstrapper::register($container, new Config([
            'database' => ['default' => ['driver' => 'sqlite', 'path' => $this->tempDir . '/db.sqlite']],
            'queue' => [
                'default' => 'sync',
                'connections' => [
                    'sync' => ['driver' => 'sync'],
                ],
            ],
        ]));
        $container->instance(BootstrapperCountingJob::class, new BootstrapperCountingJob());

        $container->get(JobDispatcher::class)->dispatch(BootstrapperCountingJob::class);

        self::assertSame(1, BootstrapperCountingJob::$handled);
    }

    public function testRegisterAcceptsNamedDatabaseConnectionsConfig(): void
    {
        $container = $this->makeContainer();

        QueueBootstrapper::register($container, new Config([
            'database' => [
                'default' => 'primary',
                'connections' => [
                    'primary' => ['driver' => 'sqlite', 'path' => $this->tempDir . '/db.sqlite'],
                ],
            ],
            'queue' => [
                'default' => 'file',
                'connections' => [
                    'file' => [
                        'driver' => 'file',
                        'path' => $this->tempDir . '/queue',
                    ],
                ],
            ],
        ]));

        self::assertInstanceOf(Queue::class, $container->get(Queue::class));
    }

    public function testRegisterCommandsAddsDefaultQueueCommands(): void
    {
        $container = $this->makeContainer();
        QueueBootstrapper::register($container, $this->config());

        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        $application = new Application('1.2.3', $stdout, $stderr);

        QueueBootstrapper::registerCommands($application, $container);
        $code = $application->run(['wayfinder', 'list']);

        rewind($stdout);
        $output = (string) stream_get_contents($stdout);

        fclose($stdout);
        fclose($stderr);

        self::assertSame(0, $code);
        self::assertStringContainsString('queue:work', $output);
        self::assertStringContainsString('queue:recover', $output);
        self::assertStringContainsString('queue:status', $output);
    }

    private function makeContainer(): Container
    {
        $container = new Container();
        $container->instance(\Wayfinder\Contracts\Container::class, $container);
        $container->instance(\Wayfinder\Support\Container::class, $container);
        $container->instance(\Wayfinder\Logging\Logger::class, new NullLogger());
        $container->instance(Database::class, new Database([
            'driver' => 'sqlite',
            'path' => $this->tempDir . '/bootstrap.sqlite',
        ]));

        return $container;
    }

    private function config(): Config
    {
        return new Config([
            'queue' => [
                'default' => 'file',
                'max_attempts' => 4,
                'connections' => [
                    'file' => [
                        'driver' => 'file',
                        'path' => $this->tempDir . '/queue',
                    ],
                ],
            ],
        ]);
    }
}

final class BootstrapperCountingJob implements \Wayfinder\Queue\Job
{
    public static int $handled = 0;

    public function handle(array $payload = []): void
    {
        self::$handled++;
    }
}
