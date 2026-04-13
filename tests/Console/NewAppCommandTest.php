<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Console;

use PHPUnit\Framework\TestCase;
use Wayfinder\Console\NewAppCommand;
use Wayfinder\Tests\Concerns\UsesTempDirectory;

final class NewAppCommandTest extends TestCase
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

    public function testCreatesNewAppFromSkeletonAndRewritesComposerName(): void
    {
        $starter = $this->tempDir . '/wayfinder-app';
        mkdir($starter . '/app', 0777, true);
        file_put_contents($starter . '/composer.json', json_encode([
            'name' => 'wayfinder/app',
            'require' => [
                'wayfinder/core' => '*',
            ],
            'repositories' => [
                [
                    'type' => 'vcs',
                    'url' => 'https://github.com/trafficinc/wayfinder-core',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents($starter . '/README.md', "# Starter\n");
        file_put_contents($starter . '/app/.gitkeep', '');

        $command = new NewAppCommand($starter, $this->tempDir);

        ob_start();
        $exit = $command->handle(['my-app']);
        ob_end_clean();

        self::assertSame(0, $exit);
        self::assertDirectoryExists($this->tempDir . '/my-app');
        self::assertFileExists($this->tempDir . '/my-app/README.md');
        self::assertFileExists($this->tempDir . '/my-app/app/.gitkeep');

        $composer = json_decode((string) file_get_contents($this->tempDir . '/my-app/composer.json'), true);
        self::assertIsArray($composer);
        self::assertSame('wayfinder/my-app', $composer['name'] ?? null);
        self::assertSame('https://github.com/trafficinc/wayfinder-core', $composer['repositories'][0]['url'] ?? null);
    }

    public function testRefusesToOverwriteExistingDirectory(): void
    {
        $starter = $this->tempDir . '/wayfinder-app';
        mkdir($starter, 0777, true);
        file_put_contents($starter . '/composer.json', "{}\n");
        mkdir($this->tempDir . '/my-app', 0777, true);

        $command = new NewAppCommand($starter, $this->tempDir);

        ob_start();
        $exit = $command->handle(['my-app']);
        ob_end_clean();

        self::assertSame(1, $exit);
    }

    public function testRequiresTargetDirectoryArgument(): void
    {
        $command = new NewAppCommand($this->tempDir . '/wayfinder-app', $this->tempDir);

        ob_start();
        $exit = $command->handle();
        ob_end_clean();

        self::assertSame(1, $exit);
    }
}
