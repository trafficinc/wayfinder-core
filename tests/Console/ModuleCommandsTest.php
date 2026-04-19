<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Console;

use PHPUnit\Framework\TestCase;
use Wayfinder\Console\ModuleInstallCommand;
use Wayfinder\Console\ModuleUninstallCommand;
use Wayfinder\Tests\Concerns\UsesTempDirectory;

final class ModuleCommandsTest extends TestCase
{
    use UsesTempDirectory;

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTempDirectory();
    }

    public function testInstallAliasRequiresPackageAndCreatesModuleSymlink(): void
    {
        $app = $this->tempDir . '/app';
        $modules = $app . '/Modules';
        mkdir($modules, 0777, true);

        $runner = function (array $command, string $cwd) use ($app): int {
            self::assertSame($app, $cwd);

            if ($command === ['composer', 'config', 'repositories.trafficinc-stackmint-auth', 'vcs', 'https://github.com/trafficinc/stackmint-auth']) {
                return 0;
            }

            if ($command === ['composer', 'require', 'trafficinc/stackmint-auth']) {
                mkdir($app . '/vendor/trafficinc/stackmint-auth', 0777, true);
                file_put_contents($app . '/vendor/trafficinc/stackmint-auth/module.php', "<?php\nreturn [];\n");

                return 0;
            }

            self::fail('Unexpected command: ' . implode(' ', $command));
        };

        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');

        $command = new ModuleInstallCommand($app, $modules, [
            'auth' => [
                'package' => 'trafficinc/stackmint-auth',
                'module' => 'Auth',
                'repository' => 'https://github.com/trafficinc/stackmint-auth',
            ],
        ], $runner, $stdout, $stderr);

        $exit = $command->handle(['auth']);

        self::assertSame(0, $exit);
        self::assertTrue(is_link($modules . '/Auth'));
        self::assertSame('../vendor/trafficinc/stackmint-auth', readlink($modules . '/Auth'));
        unlink($modules . '/Auth');

        fclose($stdout);
        fclose($stderr);
    }

    public function testInstallLocalPathCreatesSymlinkWithoutComposer(): void
    {
        $app = $this->tempDir . '/app';
        $modules = $app . '/Modules';
        $source = $this->tempDir . '/CustomModule';
        mkdir($modules, 0777, true);
        mkdir($source, 0777, true);
        file_put_contents($source . '/module.php', "<?php\nreturn [];\n");

        $runner = static function (): int {
            self::fail('Composer should not run for a direct local module link.');

            return 0;
        };

        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');

        $command = new ModuleInstallCommand($app, $modules, [], $runner, $stdout, $stderr);

        $exit = $command->handle([$source, '--module=Custom']);

        self::assertSame(0, $exit);
        self::assertTrue(is_link($modules . '/Custom'));
        self::assertSame('../../CustomModule', readlink($modules . '/Custom'));
        unlink($modules . '/Custom');

        fclose($stdout);
        fclose($stderr);
    }

    public function testUninstallAliasRemovesSymlinkAndComposerPackage(): void
    {
        $app = $this->tempDir . '/app';
        $modules = $app . '/Modules';
        mkdir($modules, 0777, true);
        mkdir($app . '/vendor/trafficinc/stackmint-auth', 0777, true);
        symlink('../../vendor/trafficinc/stackmint-auth', $modules . '/Auth');

        $calls = [];
        $runner = static function (array $command, string $cwd) use (&$calls, $app): int {
            self::assertSame($app, $cwd);
            $calls[] = $command;

            return 0;
        };

        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');

        $command = new ModuleUninstallCommand($app, $modules, [
            'auth' => [
                'package' => 'trafficinc/stackmint-auth',
                'module' => 'Auth',
                'repository' => 'https://github.com/trafficinc/stackmint-auth',
            ],
        ], $runner, $stdout, $stderr);

        $exit = $command->handle(['auth']);

        self::assertSame(0, $exit);
        self::assertFalse(file_exists($modules . '/Auth'));
        self::assertSame([
            ['composer', 'remove', 'trafficinc/stackmint-auth'],
            ['composer', 'config', '--unset', 'repositories.trafficinc-stackmint-auth'],
        ], $calls);

        fclose($stdout);
        fclose($stderr);
    }
}
