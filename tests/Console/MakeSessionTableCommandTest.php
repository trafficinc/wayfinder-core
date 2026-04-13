<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Console;

use PHPUnit\Framework\TestCase;
use Wayfinder\Console\MakeSessionTableCommand;
use Wayfinder\Tests\Concerns\UsesTempDirectory;

final class MakeSessionTableCommandTest extends TestCase
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

    public function testCreatesSessionsTableMigration(): void
    {
        $command = new MakeSessionTableCommand($this->tempDir . '/migrations');

        $exit = $command->handle();

        self::assertSame(0, $exit);

        $files = glob($this->tempDir . '/migrations/*_create_sessions_table.php') ?: [];
        self::assertCount(1, $files);

        $contents = (string) file_get_contents($files[0]);
        self::assertStringContainsString('CREATE TABLE sessions', $contents);
        self::assertStringContainsString('last_activity INTEGER NOT NULL', $contents);
    }

    public function testRefusesToCreateDuplicateSessionsMigration(): void
    {
        $path = $this->tempDir . '/migrations';
        mkdir($path, 0777, true);
        file_put_contents($path . '/202604130006_create_sessions_table.php', "<?php\n");

        $command = new MakeSessionTableCommand($path);

        ob_start();
        $exit = $command->handle();
        ob_end_clean();

        self::assertSame(1, $exit);
    }
}
