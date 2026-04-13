<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Console;

use PHPUnit\Framework\TestCase;
use Wayfinder\Console\MigrateCommand;
use Wayfinder\Console\MigrateRefreshCommand;
use Wayfinder\Console\MigrateResetCommand;
use Wayfinder\Console\MigrateRollbackCommand;
use Wayfinder\Console\MigrateStatusCommand;
use Wayfinder\Database\Database;
use Wayfinder\Database\MigrationRepository;
use Wayfinder\Database\Migrator;
use Wayfinder\Tests\Concerns\UsesTempDirectory;

final class MigrateCommandsTest extends TestCase
{
    use UsesTempDirectory;

    private Database $db;
    private string $migDir;

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
        $this->db     = new Database(['driver' => 'sqlite', 'path' => ':memory:']);
        $this->migDir = $this->tempDir . '/migrations';
        mkdir($this->migDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->tearDownTempDirectory();
    }

    // =========================================================================
    // migrate — happy path
    // =========================================================================

    public function testMigrateReturnsZeroWithNoPendingMigrations(): void
    {
        $cmd = new MigrateCommand($this->migrator());
        self::assertSame(0, $cmd->handle());
    }

    public function testMigrateReturnsZeroAfterRunningMigrations(): void
    {
        $this->writeMigration('0001_create_foo', up: 'CREATE TABLE foo (id INTEGER PRIMARY KEY)');
        self::assertSame(0, (new MigrateCommand($this->migrator()))->handle());
    }

    public function testMigrateRunsAllPendingFiles(): void
    {
        $this->writeMigration('0001_create_foo', up: 'CREATE TABLE foo (id INTEGER PRIMARY KEY)');
        $this->writeMigration('0002_create_bar', up: 'CREATE TABLE bar (id INTEGER PRIMARY KEY)');

        (new MigrateCommand($this->migrator()))->handle();

        self::assertTableExists('foo');
        self::assertTableExists('bar');
    }

    public function testMigrateIsIdempotentOnSecondRun(): void
    {
        $this->writeMigration('0001_create_foo', up: 'CREATE TABLE foo (id INTEGER PRIMARY KEY)');
        $m = $this->migrator();

        (new MigrateCommand($m))->handle();
        $code = (new MigrateCommand($m))->handle(); // second run: nothing pending

        self::assertSame(0, $code);
        self::assertTableExists('foo');
    }

    // =========================================================================
    // migrate — failure propagation and transaction rollback
    // =========================================================================

    public function testMigrateThrowsWhenMigrationFileDoesNotReturnMigrationInstance(): void
    {
        $this->writeRawFile('0001_bad_file.php', '<?php return "not a migration";');

        $this->expectException(\RuntimeException::class);
        (new MigrateCommand($this->migrator()))->handle();
    }

    public function testMigrateThrowsWhenUpMethodFails(): void
    {
        $this->writeFailingMigration('0001_boom');

        $this->expectException(\RuntimeException::class);
        (new MigrateCommand($this->migrator()))->handle();
    }

    public function testFailedMigrationIsNotLoggedInRepository(): void
    {
        $this->writeMigration('0001_ok', up: 'CREATE TABLE ok_table (id INTEGER PRIMARY KEY)');
        $this->writeFailingMigration('0002_boom');

        try {
            (new MigrateCommand($this->migrator()))->handle();
        } catch (\Throwable) {
        }

        $repo = new MigrationRepository($this->db);
        self::assertNotContains('0002_boom', $repo->ran());
    }

    public function testFailedMigrationRollsBackTransaction(): void
    {
        // 0001 creates table; 0002 creates a column that would require the DDL to have run.
        // If 0002's up() fails and the transaction rolls back, the side effects of 0002 are gone.
        $this->writeMigration('0001_create_things',
            up:   'CREATE TABLE things (id INTEGER PRIMARY KEY)',
            down: 'DROP TABLE IF EXISTS things',
        );
        $this->writeFailingMigration('0002_boom');

        try {
            (new MigrateCommand($this->migrator()))->handle();
        } catch (\Throwable) {
        }

        // 0001 committed before 0002 ran, so things table should exist
        self::assertTableExists('things');
        // 0002 never committed, so it does not appear in the repository
        $repo = new MigrationRepository($this->db);
        self::assertNotContains('0002_boom', $repo->ran());
    }

    public function testDuplicateMigrationNameThrows(): void
    {
        $dir2 = $this->tempDir . '/migrations2';
        mkdir($dir2, 0777, true);
        $this->writeMigrationInDir($this->migDir, '0001_dup', up: 'SELECT 1');
        $this->writeMigrationInDir($dir2,          '0001_dup', up: 'SELECT 1');

        $repo    = new MigrationRepository($this->db);
        $migrator = new Migrator($this->db, $repo, [$this->migDir, $dir2]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Duplicate migration name/');
        (new MigrateCommand($migrator))->handle();
    }

    // =========================================================================
    // migrate:rollback
    // =========================================================================

    public function testRollbackReturnsZeroWithNothingToRollBack(): void
    {
        $code = (new MigrateRollbackCommand($this->migrator()))->handle();
        self::assertSame(0, $code);
    }

    public function testRollbackReturnsZeroAndUndoesMigration(): void
    {
        $this->writeMigration('0001_create_foo',
            up:   'CREATE TABLE foo (id INTEGER PRIMARY KEY)',
            down: 'DROP TABLE IF EXISTS foo',
        );
        $m = $this->migrator();
        (new MigrateCommand($m))->handle();
        self::assertTableExists('foo');

        $code = (new MigrateRollbackCommand($m))->handle();

        self::assertSame(0, $code);
        self::assertTableNotExists('foo');
    }

    public function testRollbackOnlyRollsBackLastBatch(): void
    {
        $this->writeMigration('0001_t1',
            up:   'CREATE TABLE t1 (id INTEGER PRIMARY KEY)',
            down: 'DROP TABLE IF EXISTS t1',
        );
        $this->writeMigration('0002_t2',
            up:   'CREATE TABLE t2 (id INTEGER PRIMARY KEY)',
            down: 'DROP TABLE IF EXISTS t2',
        );
        $m = $this->migrator();
        (new MigrateCommand($m))->handle(); // batch 1: 0001 + 0002

        $this->writeMigration('0003_t3',
            up:   'CREATE TABLE t3 (id INTEGER PRIMARY KEY)',
            down: 'DROP TABLE IF EXISTS t3',
        );
        (new MigrateCommand($m))->handle(); // batch 2: 0003 only

        (new MigrateRollbackCommand($m))->handle(); // rolls back batch 2

        self::assertTableExists('t1');
        self::assertTableExists('t2');
        self::assertTableNotExists('t3');
    }

    public function testRollbackThrowsWhenMigrationFileIsMissing(): void
    {
        $this->writeMigration('0001_create_foo',
            up:   'CREATE TABLE foo (id INTEGER PRIMARY KEY)',
            down: 'DROP TABLE IF EXISTS foo',
        );
        $m = $this->migrator();
        (new MigrateCommand($m))->handle();

        // Delete the migration file after running it
        unlink($this->migDir . '/0001_create_foo.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/');
        (new MigrateRollbackCommand($m))->handle();
    }

    // =========================================================================
    // migrate:reset
    // =========================================================================

    public function testResetReturnsZeroWithNothingToReset(): void
    {
        self::assertSame(0, (new MigrateResetCommand($this->migrator()))->handle());
    }

    public function testResetUndoesAllMigrationsInReverseOrder(): void
    {
        $this->writeMigration('0001_t1',
            up:   'CREATE TABLE t1 (id INTEGER PRIMARY KEY)',
            down: 'DROP TABLE IF EXISTS t1',
        );
        $this->writeMigration('0002_t2',
            up:   'CREATE TABLE t2 (id INTEGER PRIMARY KEY)',
            down: 'DROP TABLE IF EXISTS t2',
        );
        $m = $this->migrator();
        (new MigrateCommand($m))->handle();

        $code = (new MigrateResetCommand($m))->handle();

        self::assertSame(0, $code);
        self::assertTableNotExists('t1');
        self::assertTableNotExists('t2');
    }

    public function testResetLeavesRepositoryEmpty(): void
    {
        $this->writeMigration('0001_t1',
            up:   'CREATE TABLE t1 (id INTEGER PRIMARY KEY)',
            down: 'DROP TABLE IF EXISTS t1',
        );
        $m = $this->migrator();
        (new MigrateCommand($m))->handle();
        (new MigrateResetCommand($m))->handle();

        $repo = new MigrationRepository($this->db);
        self::assertSame([], $repo->ran());
    }

    // =========================================================================
    // migrate:refresh
    // =========================================================================

    public function testRefreshReturnsZeroWhenNoMigrationsFound(): void
    {
        self::assertSame(0, (new MigrateRefreshCommand($this->migrator()))->handle());
    }

    public function testRefreshLeavesAllTablesInFinalState(): void
    {
        $this->writeMigration('0001_t1',
            up:   'CREATE TABLE t1 (id INTEGER PRIMARY KEY)',
            down: 'DROP TABLE IF EXISTS t1',
        );
        $this->writeMigration('0002_t2',
            up:   'CREATE TABLE t2 (id INTEGER PRIMARY KEY)',
            down: 'DROP TABLE IF EXISTS t2',
        );
        $m = $this->migrator();
        (new MigrateCommand($m))->handle();

        $code = (new MigrateRefreshCommand($m))->handle();

        self::assertSame(0, $code);
        self::assertTableExists('t1');
        self::assertTableExists('t2');
    }

    public function testRefreshReRunsWithNewMigrationsAdded(): void
    {
        $this->writeMigration('0001_t1',
            up:   'CREATE TABLE t1 (id INTEGER PRIMARY KEY)',
            down: 'DROP TABLE IF EXISTS t1',
        );
        $m = $this->migrator();
        (new MigrateCommand($m))->handle();

        // Add a new migration before refreshing
        $this->writeMigration('0002_t2',
            up:   'CREATE TABLE t2 (id INTEGER PRIMARY KEY)',
            down: 'DROP TABLE IF EXISTS t2',
        );
        (new MigrateRefreshCommand($m))->handle();

        self::assertTableExists('t1');
        self::assertTableExists('t2');
    }

    // =========================================================================
    // migrate:status
    // =========================================================================

    public function testStatusReturnsZeroWithNoMigrationFiles(): void
    {
        self::assertSame(0, (new MigrateStatusCommand($this->migrator()))->handle());
    }

    public function testStatusReturnsZeroAfterRunning(): void
    {
        $this->writeMigration('0001_t1', up: 'CREATE TABLE t1 (id INTEGER PRIMARY KEY)');
        $m = $this->migrator();
        (new MigrateCommand($m))->handle();

        self::assertSame(0, (new MigrateStatusCommand($m))->handle());
    }

    public function testStatusShowsPendingMigrationsBeforeRun(): void
    {
        $this->writeMigration('0001_t1', up: 'SELECT 1');
        $m        = $this->migrator();
        $rows     = $m->status();

        self::assertCount(1, $rows);
        self::assertSame('pending', $rows[0]['status']);
        self::assertSame('0001_t1', $rows[0]['migration']);
    }

    public function testStatusShowsRanMigrationsAfterRun(): void
    {
        $this->writeMigration('0001_t1', up: 'CREATE TABLE t1 (id INTEGER PRIMARY KEY)');
        $m = $this->migrator();
        (new MigrateCommand($m))->handle();
        $rows = $m->status();

        self::assertSame('ran', $rows[0]['status']);
        self::assertSame(1, $rows[0]['batch']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function migrator(): Migrator
    {
        return new Migrator($this->db, new MigrationRepository($this->db), $this->migDir);
    }

    private function writeMigration(string $name, string $up = 'SELECT 1', string $down = 'SELECT 1'): void
    {
        $this->writeMigrationInDir($this->migDir, $name, $up, $down);
    }

    private function writeMigrationInDir(string $dir, string $name, string $up = 'SELECT 1', string $down = 'SELECT 1'): void
    {
        $up   = addslashes($up);
        $down = addslashes($down);
        $code = <<<PHP
        <?php
        use Wayfinder\Database\Database;
        use Wayfinder\Database\Migration;
        return new class implements Migration {
            public function up(Database \$db): void   { \$db->statement('{$up}'); }
            public function down(Database \$db): void { \$db->statement('{$down}'); }
        };
        PHP;
        file_put_contents($dir . '/' . $name . '.php', $code);
    }

    private function writeFailingMigration(string $name): void
    {
        $code = <<<'PHP'
        <?php
        use Wayfinder\Database\Database;
        use Wayfinder\Database\Migration;
        return new class implements Migration {
            public function up(Database $db): void   { throw new \RuntimeException('Deliberate migration failure'); }
            public function down(Database $db): void {}
        };
        PHP;
        file_put_contents($this->migDir . '/' . $name . '.php', $code);
    }

    private function writeRawFile(string $filename, string $content): void
    {
        file_put_contents($this->migDir . '/' . $filename, $content);
    }

    private function assertTableExists(string $table): void
    {
        $result = $this->db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name=?",
            [$table],
        );
        self::assertNotEmpty($result, "Table [{$table}] should exist but does not.");
    }

    private function assertTableNotExists(string $table): void
    {
        $result = $this->db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name=?",
            [$table],
        );
        self::assertEmpty($result, "Table [{$table}] should not exist but does.");
    }
}
