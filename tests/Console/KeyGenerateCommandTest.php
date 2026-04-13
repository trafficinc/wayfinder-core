<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Console;

use PHPUnit\Framework\TestCase;
use Wayfinder\Console\KeyGenerateCommand;
use Wayfinder\Tests\Concerns\UsesTempDirectory;

final class KeyGenerateCommandTest extends TestCase
{
    use UsesTempDirectory;

    private string $envPath;

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
        $this->envPath = $this->tempDir . '/.env';
    }

    protected function tearDown(): void
    {
        $this->tearDownTempDirectory();
    }

    public function testGeneratesAppKeyInNewEnvFile(): void
    {
        $command = new KeyGenerateCommand($this->envPath);

        ob_start();
        $exit = $command->handle();
        ob_end_clean();

        self::assertSame(0, $exit);
        self::assertFileExists($this->envPath);

        $contents = (string) file_get_contents($this->envPath);
        self::assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/=]+\n$/', $contents);
    }

    public function testReplacesExistingAppKey(): void
    {
        file_put_contents($this->envPath, "APP_NAME=Wayfinder\nAPP_KEY=old-value\nAPP_ENV=local\n");
        $command = new KeyGenerateCommand($this->envPath);

        ob_start();
        $exit = $command->handle();
        ob_end_clean();

        self::assertSame(0, $exit);
        $contents = (string) file_get_contents($this->envPath);

        self::assertStringContainsString("APP_NAME=Wayfinder\n", $contents);
        self::assertStringContainsString("\nAPP_ENV=local\n", $contents);
        self::assertDoesNotMatchRegularExpression('/APP_KEY=old-value/', $contents);
        self::assertSame(1, preg_match_all('/^APP_KEY=base64:[A-Za-z0-9+\/=]+$/m', $contents));
    }

    public function testAppendsAppKeyWhenMissing(): void
    {
        file_put_contents($this->envPath, "APP_NAME=Wayfinder\nAPP_ENV=local\n");
        $command = new KeyGenerateCommand($this->envPath);

        ob_start();
        $exit = $command->handle();
        ob_end_clean();

        self::assertSame(0, $exit);
        $contents = (string) file_get_contents($this->envPath);
        self::assertStringContainsString("APP_NAME=Wayfinder\nAPP_ENV=local\nAPP_KEY=", $contents);
    }
}
