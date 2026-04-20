<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Console;

use PHPUnit\Framework\TestCase;
use Wayfinder\Console\Application;
use Wayfinder\Console\MakeDtoCommand;
use Wayfinder\Console\MakeModelCommand;
use Wayfinder\Console\MakeQueryCommand;
use Wayfinder\Tests\Concerns\UsesTempDirectory;

final class MakeDataClassCommandsTest extends TestCase
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

    public function testCommandsCanBeRegistered(): void
    {
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        $app = new Application('test', $stdout, $stderr);
        $app->add($this->makeModelCommand())
            ->add($this->makeQueryCommand())
            ->add($this->makeDtoCommand());

        $exit = $app->run(['wayfinder', 'list']);
        rewind($stdout);
        $output = (string) stream_get_contents($stdout);
        fclose($stdout);
        fclose($stderr);

        self::assertSame(0, $exit);
        self::assertStringContainsString('make:model', $output);
        self::assertStringContainsString('make:query', $output);
        self::assertStringContainsString('make:dto', $output);
    }

    public function testMakeModelCreatesAppLevelClassWithExpectedStub(): void
    {
        $command = $this->makeModelCommand();

        ob_start();
        $exit = $command->handle(['TaskItem', '--namespace=Domain/Admin']);
        ob_end_clean();

        $file = $this->tempDir . '/app/Models/Domain/Admin/TaskItem.php';

        self::assertSame(0, $exit);
        self::assertFileExists($file);

        $contents = (string) file_get_contents($file);
        self::assertStringContainsString('namespace App\\Models\\Domain\\Admin;', $contents);
        self::assertStringContainsString('final class TaskItem extends Model', $contents);
        self::assertStringContainsString("protected static string \$table = 'task_items';", $contents);
    }

    public function testMakeQueryCreatesModuleLevelClassWithExpectedStub(): void
    {
        $command = $this->makeQueryCommand();

        ob_start();
        $exit = $command->handle(['ProjectReport', '--module=Tasks', '--namespace=Reports']);
        ob_end_clean();

        $file = $this->tempDir . '/Modules/Tasks/Queries/Reports/ProjectReportQuery.php';

        self::assertSame(0, $exit);
        self::assertFileExists($file);

        $contents = (string) file_get_contents($file);
        self::assertStringContainsString('namespace Modules\\Tasks\\Queries\\Reports;', $contents);
        self::assertStringContainsString('final class ProjectReportQuery extends Query', $contents);
        self::assertStringContainsString('public function execute(): array', $contents);
    }

    public function testMakeDtoCreatesModuleLevelClassWithExpectedStub(): void
    {
        $command = $this->makeDtoCommand();

        ob_start();
        $exit = $command->handle(['TaskSummary', '--module=Projects']);
        ob_end_clean();

        $file = $this->tempDir . '/Modules/Projects/DTOs/TaskSummaryData.php';

        self::assertSame(0, $exit);
        self::assertFileExists($file);

        $contents = (string) file_get_contents($file);
        self::assertStringContainsString('namespace Modules\\Projects\\DTOs;', $contents);
        self::assertStringContainsString('final class TaskSummaryData extends DataTransferObject', $contents);
    }

    public function testMakeModelRefusesToOverwriteExistingFile(): void
    {
        $command = $this->makeModelCommand();
        $target = $this->tempDir . '/app/Models/User.php';
        mkdir(dirname($target), 0777, true);
        file_put_contents($target, "<?php\n");

        ob_start();
        $exit = $command->handle(['User']);
        ob_end_clean();

        self::assertSame(1, $exit);
    }

    private function makeModelCommand(): MakeModelCommand
    {
        return new MakeModelCommand($this->tempDir . '/app', 'App', $this->tempDir . '/Modules', 'Modules');
    }

    private function makeQueryCommand(): MakeQueryCommand
    {
        return new MakeQueryCommand($this->tempDir . '/app', 'App', $this->tempDir . '/Modules', 'Modules');
    }

    private function makeDtoCommand(): MakeDtoCommand
    {
        return new MakeDtoCommand($this->tempDir . '/app', 'App', $this->tempDir . '/Modules', 'Modules');
    }
}
