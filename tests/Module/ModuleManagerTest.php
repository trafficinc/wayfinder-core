<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Module;

use PHPUnit\Framework\TestCase;
use Wayfinder\Module\Module;
use Wayfinder\Module\ModuleManager;
use Wayfinder\Module\ServiceProvider;
use Wayfinder\Routing\Router;
use Wayfinder\Support\Config;
use Wayfinder\Support\Container;
use Wayfinder\Support\EventDispatcher;
use Wayfinder\Tests\Concerns\UsesTempDirectory;
use Wayfinder\View\View;

final class ModuleManagerTest extends TestCase
{
    use UsesTempDirectory;

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
        RecordingModuleProvider::reset();
    }

    protected function tearDown(): void
    {
        $this->tearDownTempDirectory();
    }

    public function test_discovers_modules_and_applies_enabled_and_order_configuration(): void
    {
        $this->makeModule('Shop', ['order' => 10]);
        $this->makeModule('Blog', ['order' => 20]);

        $manager = new ModuleManager($this->tempDir);
        $modules = $manager->modules(new Config([
            'modules' => [
                'enabled' => ['Shop' => false],
                'order' => ['Blog'],
            ],
        ]));

        self::assertCount(1, $modules);
        self::assertSame('Blog', $modules[0]->name());
    }

    public function test_merges_module_config_without_overwriting_existing_app_values(): void
    {
        $this->makeModule('Blog', [], [
            'app' => ['name' => 'Module Name', 'tagline' => 'From module'],
            'blog' => ['headline' => 'Module headline'],
        ]);

        $config = new Config([
            'app' => ['name' => 'App Name'],
        ]);

        $manager = new ModuleManager($this->tempDir);
        $manager->mergeConfig($config);

        self::assertSame('App Name', $config->get('app.name'));
        self::assertSame('From module', $config->get('app.tagline'));
        self::assertSame('Module headline', $config->get('blog.headline'));
    }

    public function test_registers_and_boots_module_provider_and_collects_resources(): void
    {
        $this->makeModule('Blog', [
            'provider' => RecordingModuleProvider::class,
        ], [
            'blog' => ['headline' => 'Recorded'],
        ], withRoutes: true, withViews: true, withMigrations: true);

        $config = new Config([
            'modules' => [],
        ]);
        $manager = new ModuleManager($this->tempDir);
        $manager->mergeConfig($config);

        $container = new Container();
        $events = new EventDispatcher();
        $router = new Router($container, $events);
        $view = new View($this->tempDir . '/app-views');

        $manager->registerProviders($container, $config);
        $manager->bootProviders($container, $router, $config);
        $manager->registerViews($view, $config);

        self::assertTrue($container->has('module.provider.registered'));
        self::assertSame(['Blog'], RecordingModuleProvider::$registered);
        self::assertSame(['Blog'], RecordingModuleProvider::$booted);
        self::assertSame([$this->tempDir . '/Blog/routes/web.php'], $manager->routeFiles($config));
        self::assertSame([$this->tempDir . '/Blog/database/migrations'], $manager->migrationPaths($config));
        self::assertSame('module view', trim($view->render('blog::index')));
    }

    public function test_uses_manifest_cache_when_enabled(): void
    {
        $cachePath = $this->tempDir . '/cache/modules.php';
        $this->makeModule('Blog');

        $config = new Config(['modules' => []]);
        $first = new ModuleManager($this->tempDir, $cachePath, true);
        $first->modules($config);

        self::assertFileExists($cachePath);

        $this->removeDirectory($this->tempDir . '/Blog');

        $second = new ModuleManager($this->tempDir, $cachePath, true);
        $modules = $second->modules($config);

        self::assertCount(1, $modules);
        self::assertSame('Blog', $modules[0]->name());
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, array<string, mixed>> $configFiles
     */
    private function makeModule(
        string $name,
        array $metadata = [],
        array $configFiles = [],
        bool $withRoutes = false,
        bool $withViews = false,
        bool $withMigrations = false,
    ): Module {
        $base = $this->tempDir . '/' . $name;
        mkdir($base, 0777, true);
        file_put_contents($base . '/module.php', '<?php return ' . var_export($metadata, true) . ';');

        if ($configFiles !== []) {
            mkdir($base . '/config', 0777, true);

            foreach ($configFiles as $file => $values) {
                file_put_contents($base . '/config/' . $file . '.php', '<?php return ' . var_export($values, true) . ';');
            }
        }

        if ($withRoutes) {
            mkdir($base . '/routes', 0777, true);
            file_put_contents($base . '/routes/web.php', '<?php');
        }

        if ($withViews) {
            mkdir($base . '/resources/views', 0777, true);
            file_put_contents($base . '/resources/views/index.php', 'module view');
        }

        if ($withMigrations) {
            mkdir($base . '/database/migrations', 0777, true);
            file_put_contents($base . '/database/migrations/20260412190000_example.php', '<?php return null;');
        }

        return Module::fromArray([
            'name' => $name,
            'path' => $base,
            'namespace' => 'Modules\\' . $name . '\\',
        ]);
    }
}

final class RecordingModuleProvider extends ServiceProvider
{
    /**
     * @var list<string>
     */
    public static array $registered = [];

    /**
     * @var list<string>
     */
    public static array $booted = [];

    public static function reset(): void
    {
        self::$registered = [];
        self::$booted = [];
    }

    public function register(Container $container, Config $config, Module $module): void
    {
        self::$registered[] = $module->name();
        $container->instance('module.provider.registered', true);
    }

    public function boot(Container $container, Router $router, Config $config, Module $module): void
    {
        self::$booted[] = $module->name();
    }
}
