<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Logging;

use PHPUnit\Framework\TestCase;
use Wayfinder\Logging\FileLogger;
use Wayfinder\Tests\Concerns\UsesTempDirectory;

final class FileLoggerTest extends TestCase
{
    use UsesTempDirectory;

    private string $logFile;
    private FileLogger $logger;

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
        $this->logFile = $this->tempDir . '/app.log';
        $this->logger = new FileLogger($this->logFile);
    }

    protected function tearDown(): void
    {
        $this->tearDownTempDirectory();
    }

    private function logLines(): array
    {
        if (! is_file($this->logFile)) {
            return [];
        }
        return array_filter(explode("\n", (string) file_get_contents($this->logFile)));
    }

    // -------------------------------------------------------------------------
    // Log level methods
    // -------------------------------------------------------------------------

    public function testDebugWritesLine(): void
    {
        $this->logger->debug('Debug message');

        $lines = $this->logLines();
        self::assertCount(1, $lines);
        self::assertStringContainsString('DEBUG', $lines[0]);
        self::assertStringContainsString('Debug message', $lines[0]);
    }

    public function testInfoWritesLine(): void
    {
        $this->logger->info('Info message');

        $lines = $this->logLines();
        self::assertStringContainsString('INFO', $lines[0]);
        self::assertStringContainsString('Info message', $lines[0]);
    }

    public function testWarningWritesLine(): void
    {
        $this->logger->warning('Warning message');

        $lines = $this->logLines();
        self::assertStringContainsString('WARNING', $lines[0]);
    }

    public function testErrorWritesLine(): void
    {
        $this->logger->error('Error message');

        $lines = $this->logLines();
        self::assertStringContainsString('ERROR', $lines[0]);
    }

    // -------------------------------------------------------------------------
    // Line format
    // -------------------------------------------------------------------------

    public function testLogLineContainsTimestamp(): void
    {
        $this->logger->info('Test');

        $line = $this->logLines()[0];
        self::assertMatchesRegularExpression('/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $line);
    }

    public function testLogLineFormatIsCorrect(): void
    {
        $this->logger->info('Hello world');

        $line = $this->logLines()[0];
        self::assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] INFO: Hello world$/', $line);
    }

    public function testContextIsJsonEncodedOnSameLine(): void
    {
        $this->logger->info('User logged in', ['user_id' => 42, 'ip' => '127.0.0.1']);

        $line = $this->logLines()[0];
        self::assertStringContainsString('{"user_id":42,"ip":"127.0.0.1"}', $line);
    }

    public function testEmptyContextProducesNoJsonSuffix(): void
    {
        $this->logger->info('Clean message');

        $line = $this->logLines()[0];
        self::assertStringNotContainsString('{', $line);
    }

    public function testMultipleLogCallsAppendLines(): void
    {
        $this->logger->info('First');
        $this->logger->info('Second');
        $this->logger->info('Third');

        self::assertCount(3, $this->logLines());
    }

    // -------------------------------------------------------------------------
    // Level filtering
    // -------------------------------------------------------------------------

    public function testWarningThresholdDropsDebugAndInfo(): void
    {
        $logger = new FileLogger($this->logFile, 'warning');

        $logger->debug('debug msg');
        $logger->info('info msg');
        $logger->warning('warning msg');
        $logger->error('error msg');

        $lines = $this->logLines();
        self::assertCount(2, $lines);
        self::assertStringContainsString('WARNING', $lines[0]);
        self::assertStringContainsString('ERROR', $lines[1]);
    }

    public function testErrorThresholdDropsEverythingBelow(): void
    {
        $logger = new FileLogger($this->logFile, 'error');

        $logger->debug('x');
        $logger->info('x');
        $logger->warning('x');
        $logger->error('only this');

        self::assertCount(1, $this->logLines());
    }

    public function testDebugThresholdAllowsAllLevels(): void
    {
        $logger = new FileLogger($this->logFile, 'debug');

        $logger->debug('d');
        $logger->info('i');
        $logger->warning('w');
        $logger->error('e');

        self::assertCount(4, $this->logLines());
    }

    // -------------------------------------------------------------------------
    // Error conditions
    // -------------------------------------------------------------------------

    public function testInvalidLogLevelThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->logger->log('critical', 'message');
    }

    public function testInvalidThresholdLevelThrowsOnConstruct(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $logger = new FileLogger($this->logFile, 'verbose');
        $logger->info('trigger normalizeLevel');
    }

    public function testCreatesDirectoryIfMissing(): void
    {
        $path = $this->tempDir . '/logs/nested/app.log';
        $logger = new FileLogger($path);

        $logger->info('Test');

        self::assertFileExists($path);
    }

    // -------------------------------------------------------------------------
    // Robustness
    // -------------------------------------------------------------------------

    public function testVeryLargeContextPayloadDoesNotThrow(): void
    {
        $context = [];
        for ($i = 0; $i < 1000; $i++) {
            $context["key_{$i}"] = str_repeat('x', 100);
        }

        $this->logger->info('Large context', $context);

        self::assertCount(1, $this->logLines());
    }

    public function testNestedContextArraysAreEncoded(): void
    {
        $this->logger->error('Nested', ['exception' => ['class' => 'RuntimeException', 'line' => 42]]);

        $line = $this->logLines()[0];
        self::assertStringContainsString('RuntimeException', $line);
    }

    public function testLogLevelCaseInsensitive(): void
    {
        $this->logger->log('INFO', 'uppercase level');
        $this->logger->log('Error', 'mixed case level');

        $lines = $this->logLines();
        self::assertCount(2, $lines);
    }
}
