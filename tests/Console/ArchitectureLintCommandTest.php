<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Console;

use PHPUnit\Framework\TestCase;
use Wayfinder\Console\Application;
use Wayfinder\Console\ArchitectureLintCommand;
use Wayfinder\Tests\Concerns\UsesTempDirectory;

final class ArchitectureLintCommandTest extends TestCase
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

    public function testCommandCanBeRegistered(): void
    {
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        $app = new Application('test', $stdout, $stderr);
        $app->add(new ArchitectureLintCommand($this->tempDir, $stdout, $stderr));

        $exit = $app->run(['wayfinder', 'list']);
        rewind($stdout);
        $output = (string) stream_get_contents($stdout);
        fclose($stdout);
        fclose($stderr);

        self::assertSame(0, $exit);
        self::assertStringContainsString('lint:architecture', $output);
    }

    public function testCommandReturnsZeroWhenNoViolationsExist(): void
    {
        mkdir($this->tempDir . '/app/Controllers', 0777, true);
        file_put_contents($this->tempDir . '/app/Controllers/HomeController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Controllers;

final class HomeController
{
}
PHP);

        [$exit, $stdout, $stderr] = $this->runCommand();

        self::assertSame(0, $exit);
        self::assertStringContainsString('No architecture violations found.', $stdout);
        self::assertSame('', $stderr);
    }

    public function testCommandReportsViolationsWithFileAndLineNumbers(): void
    {
        mkdir($this->tempDir . '/app/Controllers', 0777, true);
        file_put_contents($this->tempDir . '/app/Controllers/TaskController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Controllers;

use Wayfinder\Database\DB;
use Wayfinder\Database\Database;

final class TaskController
{
    public function __invoke(Database $database): void
    {
        DB::table('tasks')->get();
        $database->statement('DELETE FROM tasks');
    }
}
PHP);

        [$exit, $stdout, $stderr] = $this->runCommand();

        self::assertSame(1, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Architecture violations found:', $stderr);
        self::assertStringContainsString('/app/Controllers/TaskController.php:14 matches DB::table', $stderr);
        self::assertStringContainsString('/app/Controllers/TaskController.php:15 matches Database::statement', $stderr);
    }

    public function testCommandCanScanExplicitPathsOnly(): void
    {
        mkdir($this->tempDir . '/Modules/Tasks/Controllers', 0777, true);
        file_put_contents($this->tempDir . '/Modules/Tasks/Controllers/TaskController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Modules\Tasks\Controllers;

use Wayfinder\Database\DB;

final class TaskController
{
    public function __invoke(): void
    {
        DB::raw('SELECT 1');
    }
}
PHP);

        [$exit, , $stderr] = $this->runCommand(['Modules/Tasks']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('DB::raw', $stderr);
    }

    public function testCommandPassesForCurrentTaskApp(): void
    {
        $projectRoot = dirname(__DIR__, 3) . '/task-app';
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        $app = new Application('test', $stdout, $stderr);
        $app->add(new ArchitectureLintCommand($projectRoot, $stdout, $stderr));

        $exit = $app->run(['wayfinder', 'lint:architecture']);

        rewind($stdout);
        rewind($stderr);
        $outText = (string) stream_get_contents($stdout);
        $errText = (string) stream_get_contents($stderr);

        fclose($stdout);
        fclose($stderr);

        self::assertSame(0, $exit);
        self::assertStringContainsString('No architecture violations found.', $outText);
        self::assertSame('', $errText);
    }

    /**
     * @param list<string> $arguments
     * @return array{0: int, 1: string, 2: string}
     */
    private function runCommand(array $arguments = []): array
    {
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        $app = new Application('test', $stdout, $stderr);
        $app->add(new ArchitectureLintCommand($this->tempDir, $stdout, $stderr));

        $exit = $app->run(['wayfinder', 'lint:architecture', ...$arguments]);

        rewind($stdout);
        rewind($stderr);
        $outText = (string) stream_get_contents($stdout);
        $errText = (string) stream_get_contents($stderr);

        fclose($stdout);
        fclose($stderr);

        return [$exit, $outText, $errText];
    }
}
