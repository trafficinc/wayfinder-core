<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Console;

use PHPUnit\Framework\TestCase;
use Wayfinder\Console\ConfigCacheCommand;
use Wayfinder\Console\ConfigClearCommand;
use Wayfinder\Console\QueueWorkCommand;
use Wayfinder\Console\RouteCacheCommand;
use Wayfinder\Console\RouteClearCommand;
use Wayfinder\Http\Response;
use Wayfinder\Logging\NullLogger;
use Wayfinder\Queue\FileQueue;
use Wayfinder\Queue\Job;
use Wayfinder\Queue\Worker;
use Wayfinder\Routing\Router;
use Wayfinder\Support\Config;
use Wayfinder\Support\Container;
use Wayfinder\Tests\Concerns\UsesTempDirectory;

final class CacheAndQueueCommandsTest extends TestCase
{
    use UsesTempDirectory;

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
        CacheQueueCountingJob::$handled = 0;
    }

    protected function tearDown(): void
    {
        $this->tearDownTempDirectory();
    }

    // =========================================================================
    // route:cache
    // =========================================================================

    public function testRouteCacheReturnsZeroInProductionEnvironment(): void
    {
        $path = $this->tempDir . '/bootstrap/cache/routes.php';

        $code = $this->makeRouteCacheCommand(environment: 'production', cachePath: $path)->handle();

        self::assertSame(0, $code);
        self::assertFileExists($path);
    }

    public function testRouteCacheWritesValidPhpManifest(): void
    {
        $path   = $this->tempDir . '/bootstrap/cache/routes.php';
        $router = $this->routerWithStringRoutes();

        (new RouteCacheCommand($router, $this->productionConfig(), $path))->handle();

        $manifest = require $path;
        self::assertIsArray($manifest);
        self::assertNotEmpty($manifest);
    }

    public function testRouteCacheManifestContainsRegisteredRoutes(): void
    {
        $path   = $this->tempDir . '/bootstrap/cache/routes.php';
        $router = new Router();
        $router->get('/users', ['UserController', 'index'], name: 'users.index');
        $router->post('/users', ['UserController', 'store']);

        (new RouteCacheCommand($router, $this->productionConfig(), $path))->handle();

        $manifest = require $path;
        $paths    = array_column($manifest, 'path');

        self::assertContains('/users', $paths);
        self::assertSame(2, count(array_filter($manifest, fn ($r) => $r['path'] === '/users')));
    }

    public function testRouteCacheThrowsInLocalEnvironment(): void
    {
        $path = $this->tempDir . '/routes.php';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/local/');
        $this->makeRouteCacheCommand(environment: 'local', cachePath: $path)->handle();
    }

    public function testRouteCacheThrowsInDevelopmentEnvironment(): void
    {
        $path = $this->tempDir . '/routes.php';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/development/');
        $this->makeRouteCacheCommand(environment: 'development', cachePath: $path)->handle();
    }

    public function testRouteCacheThrowsForClosureHandlers(): void
    {
        $router = new Router();
        $router->get('/', static fn (): Response => Response::text('ok'));

        $this->expectException(\RuntimeException::class);
        (new RouteCacheCommand($router, $this->productionConfig(), $this->tempDir . '/r.php'))->handle();
    }

    public function testRouteCacheReturnsOneWhenDirectoryCannotBeCreated(): void
    {
        $this->skipIfRoot();

        $locked = $this->tempDir . '/locked';
        mkdir($locked, 0000, true);

        $code = $this->makeRouteCacheCommand(
            environment: 'production',
            cachePath: $locked . '/sub/routes.php',
        )->handle();

        chmod($locked, 0777);
        self::assertSame(1, $code);
    }

    // =========================================================================
    // route:clear
    // =========================================================================

    public function testRouteClearReturnsZeroWhenNoCacheExists(): void
    {
        $code = (new RouteClearCommand($this->tempDir . '/no-such-file.php'))->handle();
        self::assertSame(0, $code);
    }

    public function testRouteClearDeletesExistingCacheFile(): void
    {
        $path = $this->tempDir . '/routes.php';
        file_put_contents($path, '<?php return [];');

        (new RouteClearCommand($path))->handle();

        self::assertFileDoesNotExist($path);
    }

    public function testRouteClearReturnsZeroAfterDeletion(): void
    {
        $path = $this->tempDir . '/routes.php';
        file_put_contents($path, '<?php return [];');

        $code = (new RouteClearCommand($path))->handle();

        self::assertSame(0, $code);
    }

    public function testRouteClearReturnsOneWhenFileCannotBeDeleted(): void
    {
        $this->skipIfRoot();

        $dir  = $this->tempDir . '/protected';
        $path = $dir . '/routes.php';
        mkdir($dir, 0777, true);
        file_put_contents($path, '<?php return [];');
        chmod($dir, 0555); // read+execute, no write → cannot unlink inside

        $code = (new RouteClearCommand($path))->handle();

        chmod($dir, 0777);
        self::assertSame(1, $code);
    }

    // =========================================================================
    // config:cache
    // =========================================================================

    public function testConfigCacheReturnsZeroAndWritesFile(): void
    {
        $path   = $this->tempDir . '/bootstrap/cache/config.php';
        $config = new Config(['app' => ['name' => 'Wayfinder', 'debug' => false]]);

        $code = (new ConfigCacheCommand($config, $path))->handle();

        self::assertSame(0, $code);
        self::assertFileExists($path);
    }

    public function testConfigCacheWritesValidPhpPayload(): void
    {
        $path   = $this->tempDir . '/cache/config.php';
        $config = new Config(['database' => ['driver' => 'sqlite'], 'app' => ['env' => 'production']]);

        (new ConfigCacheCommand($config, $path))->handle();

        $loaded = require $path;
        self::assertIsArray($loaded);
        self::assertSame('sqlite', $loaded['database']['driver']);
        self::assertSame('production', $loaded['app']['env']);
    }

    public function testConfigCacheCreatesIntermediateDirectories(): void
    {
        $path = $this->tempDir . '/deep/nested/path/config.php';

        (new ConfigCacheCommand(new Config(), $path))->handle();

        self::assertFileExists($path);
    }

    public function testConfigCacheReturnsOneWhenDirectoryCannotBeCreated(): void
    {
        $this->skipIfRoot();

        $locked = $this->tempDir . '/locked_cfg';
        mkdir($locked, 0000, true);

        $code = (new ConfigCacheCommand(new Config(), $locked . '/sub/config.php'))->handle();

        chmod($locked, 0777);
        self::assertSame(1, $code);
    }

    // =========================================================================
    // config:clear
    // =========================================================================

    public function testConfigClearReturnsZeroWhenNoCacheExists(): void
    {
        $code = (new ConfigClearCommand($this->tempDir . '/no-such-config.php'))->handle();
        self::assertSame(0, $code);
    }

    public function testConfigClearDeletesExistingCacheFile(): void
    {
        $path = $this->tempDir . '/config.php';
        file_put_contents($path, '<?php return [];');

        (new ConfigClearCommand($path))->handle();

        self::assertFileDoesNotExist($path);
    }

    public function testConfigClearReturnsZeroAfterDeletion(): void
    {
        $path = $this->tempDir . '/config.php';
        file_put_contents($path, '<?php return [];');

        $code = (new ConfigClearCommand($path))->handle();

        self::assertSame(0, $code);
    }

    public function testConfigClearReturnsOneWhenFileCannotBeDeleted(): void
    {
        $this->skipIfRoot();

        $dir  = $this->tempDir . '/cfg_protected';
        $path = $dir . '/config.php';
        mkdir($dir, 0777, true);
        file_put_contents($path, '<?php return [];');
        chmod($dir, 0555);

        $code = (new ConfigClearCommand($path))->handle();

        chmod($dir, 0777);
        self::assertSame(1, $code);
    }

    // =========================================================================
    // queue:work — exit codes
    // =========================================================================

    public function testQueueWorkReturnsZeroWithEmptyQueue(): void
    {
        $cmd = new QueueWorkCommand($this->makeWorker());
        self::assertSame(0, $cmd->handle());
    }

    public function testQueueWorkReturnsZeroAfterSuccessfulJob(): void
    {
        $container = new Container();
        $container->instance(CacheQueueCountingJob::class, new CacheQueueCountingJob());
        $queue = new FileQueue($this->tempDir . '/queue');
        $queue->push(CacheQueueCountingJob::class);

        $cmd  = new QueueWorkCommand(new Worker($queue, $container, new NullLogger()));
        $code = $cmd->handle();

        self::assertSame(0, $code);
        self::assertSame(1, CacheQueueCountingJob::$handled);
    }

    public function testQueueWorkReturnsOneAfterPermanentJobFailure(): void
    {
        $container = new Container();
        $container->instance(CacheQueueFailingJob::class, new CacheQueueFailingJob());
        $queue = new FileQueue($this->tempDir . '/queue');
        $queue->push(CacheQueueFailingJob::class);

        // maxAttempts:1 ensures first failure is permanent
        $cmd  = new QueueWorkCommand(new Worker($queue, $container, new NullLogger(), maxAttempts: 1));
        $code = $cmd->handle();

        self::assertSame(1, $code);
    }

    public function testQueueWorkReturnsZeroAfterReleasedJob(): void
    {
        // A released job (retryable failure) is not a fatal error — exit 0
        $container = new Container();
        $container->instance(CacheQueueFailingJob::class, new CacheQueueFailingJob());
        $queue = new FileQueue($this->tempDir . '/queue');
        $queue->push(CacheQueueFailingJob::class);

        // maxAttempts:3, first failure → released → exit 0
        $cmd  = new QueueWorkCommand(new Worker($queue, $container, new NullLogger(), maxAttempts: 3));
        $code = $cmd->handle();

        self::assertSame(0, $code);
        self::assertSame(1, $queue->size(), 'Released job must be re-queued');
    }

    public function testQueueWorkLeavesProcessingCleanAfterSuccess(): void
    {
        $container = new Container();
        $container->instance(CacheQueueCountingJob::class, new CacheQueueCountingJob());
        $queue = new FileQueue($this->tempDir . '/queue');
        $queue->push(CacheQueueCountingJob::class);

        (new QueueWorkCommand(new Worker($queue, $container, new NullLogger())))->handle();

        self::assertSame(0, $queue->processingSize());
        self::assertSame(0, $queue->size());
    }

    public function testQueueWorkMovesExhaustedJobToFailedQueue(): void
    {
        $container = new Container();
        $container->instance(CacheQueueFailingJob::class, new CacheQueueFailingJob());
        $queue = new FileQueue($this->tempDir . '/queue');
        $queue->push(CacheQueueFailingJob::class);

        $worker = new Worker($queue, $container, new NullLogger(), maxAttempts: 1);
        (new QueueWorkCommand($worker))->handle();

        self::assertSame(0, $queue->size());
        self::assertSame(0, $queue->processingSize());
        self::assertSame(1, $queue->failedSize());
    }

    // =========================================================================
    // route:cache + route:clear round-trip
    // =========================================================================

    public function testCacheAndClearRoundTrip(): void
    {
        $path   = $this->tempDir . '/routes.php';
        $router = $this->routerWithStringRoutes();

        (new RouteCacheCommand($router, $this->productionConfig(), $path))->handle();
        self::assertFileExists($path);

        (new RouteClearCommand($path))->handle();
        self::assertFileDoesNotExist($path);
    }

    public function testCacheThenClearThenCacheAgainSucceeds(): void
    {
        $path   = $this->tempDir . '/routes.php';
        $router = $this->routerWithStringRoutes();
        $config = $this->productionConfig();

        (new RouteCacheCommand($router, $config, $path))->handle();
        (new RouteClearCommand($path))->handle();
        $code = (new RouteCacheCommand($router, $config, $path))->handle();

        self::assertSame(0, $code);
        self::assertFileExists($path);
    }

    // =========================================================================
    // config:cache + config:clear round-trip
    // =========================================================================

    public function testConfigCacheAndClearRoundTrip(): void
    {
        $path   = $this->tempDir . '/config.php';
        $config = new Config(['app' => ['name' => 'Test']]);

        (new ConfigCacheCommand($config, $path))->handle();
        self::assertFileExists($path);

        (new ConfigClearCommand($path))->handle();
        self::assertFileDoesNotExist($path);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeRouteCacheCommand(string $environment, string $cachePath): RouteCacheCommand
    {
        return new RouteCacheCommand(
            $this->routerWithStringRoutes(),
            new Config(['app' => ['environment' => $environment]]),
            $cachePath,
        );
    }

    private function productionConfig(): Config
    {
        return new Config(['app' => ['environment' => 'production']]);
    }

    private function routerWithStringRoutes(): Router
    {
        $router = new Router();
        $router->get('/', ['HomeController', 'index']);
        $router->post('/users', ['UserController', 'store']);
        return $router;
    }

    private function makeWorker(): Worker
    {
        return new Worker(
            new FileQueue($this->tempDir . '/queue'),
            new Container(),
            new NullLogger(),
        );
    }

    private function skipIfRoot(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            self::markTestSkipped('chmod restrictions have no effect when running as root.');
        }
    }
}

// ---------------------------------------------------------------------------
// Fixture job classes
// ---------------------------------------------------------------------------

final class CacheQueueCountingJob implements Job
{
    public static int $handled = 0;

    public function handle(array $payload = []): void
    {
        self::$handled++;
    }
}

final class CacheQueueFailingJob implements Job
{
    public function handle(array $payload = []): void
    {
        throw new \RuntimeException('Deliberate job failure');
    }
}
