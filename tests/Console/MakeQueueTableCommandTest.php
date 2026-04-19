<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Console;

use PHPUnit\Framework\TestCase;
use Wayfinder\Console\MakeQueueTableCommand;
use Wayfinder\Tests\Concerns\UsesTempDirectory;

final class MakeQueueTableCommandTest extends TestCase
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

    public function testCreatesQueueTableMigration(): void
    {
        $command = new MakeQueueTableCommand($this->tempDir . '/migrations');

        $exit = $command->handle();

        self::assertSame(0, $exit);

        $files = glob($this->tempDir . '/migrations/*_create_jobs_table.php') ?: [];
        self::assertCount(1, $files);

        $contents = (string) file_get_contents($files[0]);
        self::assertStringContainsString('CREATE TABLE jobs', $contents);
        self::assertStringContainsString('processing_started_at', $contents);
    }

    public function testRefusesToCreateDuplicateQueueMigration(): void
    {
        $path = $this->tempDir . '/migrations';
        mkdir($path, 0777, true);
        file_put_contents($path . '/202604130006_create_jobs_table.php', "<?php\n");

        $command = new MakeQueueTableCommand($path);

        ob_start();
        $exit = $command->handle();
        ob_end_clean();

        self::assertSame(1, $exit);
    }
}
