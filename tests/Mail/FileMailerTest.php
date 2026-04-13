<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Wayfinder\Mail\FileMailer;
use Wayfinder\Mail\MailMessage;
use Wayfinder\Tests\Concerns\UsesTempDirectory;

final class FileMailerTest extends TestCase
{
    use UsesTempDirectory;

    private FileMailer $mailer;

    protected function setUp(): void
    {
        $this->setUpTempDirectory();
        $this->mailer = new FileMailer($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->tearDownTempDirectory();
    }

    private function message(
        string $to = 'user@example.com',
        string $subject = 'Hello',
        string $text = 'Plain body',
        ?string $html = null,
    ): MailMessage {
        return new MailMessage($to, $subject, $text, $html);
    }

    // -------------------------------------------------------------------------
    // Basic send
    // -------------------------------------------------------------------------

    public function testSendCreatesMailFile(): void
    {
        $this->mailer->send($this->message());

        $files = glob($this->tempDir . '/*.mail') ?: [];
        self::assertCount(1, $files);
    }

    public function testSendWritesCorrectRecipient(): void
    {
        $this->mailer->send($this->message(to: 'ron@example.com'));

        $file = (glob($this->tempDir . '/*.mail') ?: [])[0];
        $payload = json_decode((string) file_get_contents($file), true);

        self::assertSame('ron@example.com', $payload['to']);
    }

    public function testSendWritesCorrectSubjectAndBody(): void
    {
        $this->mailer->send($this->message(subject: 'Welcome!', text: 'Thanks for joining.'));

        $file = (glob($this->tempDir . '/*.mail') ?: [])[0];
        $payload = json_decode((string) file_get_contents($file), true);

        self::assertSame('Welcome!', $payload['subject']);
        self::assertSame('Thanks for joining.', $payload['text']);
    }

    public function testSendIncludesHtmlWhenProvided(): void
    {
        $this->mailer->send($this->message(html: '<p>Hello</p>'));

        $file = (glob($this->tempDir . '/*.mail') ?: [])[0];
        $payload = json_decode((string) file_get_contents($file), true);

        self::assertSame('<p>Hello</p>', $payload['html']);
    }

    public function testSendStoresNullHtmlWhenNotProvided(): void
    {
        $this->mailer->send($this->message());

        $file = (glob($this->tempDir . '/*.mail') ?: [])[0];
        $payload = json_decode((string) file_get_contents($file), true);

        self::assertNull($payload['html']);
    }

    public function testSendIncludesSentAt(): void
    {
        $this->mailer->send($this->message());

        $file = (glob($this->tempDir . '/*.mail') ?: [])[0];
        $payload = json_decode((string) file_get_contents($file), true);

        self::assertArrayHasKey('sent_at', $payload);
        self::assertNotEmpty($payload['sent_at']);
    }

    // -------------------------------------------------------------------------
    // Multiple sends
    // -------------------------------------------------------------------------

    public function testMultipleSendsCreateMultipleFiles(): void
    {
        $this->mailer->send($this->message(to: 'a@example.com'));
        $this->mailer->send($this->message(to: 'b@example.com'));
        $this->mailer->send($this->message(to: 'c@example.com'));

        $files = glob($this->tempDir . '/*.mail') ?: [];
        self::assertCount(3, $files);
    }

    public function testEachSendWritesSeparateFile(): void
    {
        $this->mailer->send($this->message(to: 'first@example.com'));
        usleep(1000);
        $this->mailer->send($this->message(to: 'second@example.com'));

        $files = glob($this->tempDir . '/*.mail') ?: [];
        $recipients = array_map(static function (string $file): string {
            $payload = json_decode((string) file_get_contents($file), true);
            return $payload['to'] ?? '';
        }, $files);

        self::assertContains('first@example.com', $recipients);
        self::assertContains('second@example.com', $recipients);
    }

    // -------------------------------------------------------------------------
    // Directory creation
    // -------------------------------------------------------------------------

    public function testCreatesDirectoryIfMissing(): void
    {
        $dir = $this->tempDir . '/mail/outbox';
        $mailer = new FileMailer($dir);

        $mailer->send($this->message());

        self::assertDirectoryExists($dir);
        self::assertCount(1, glob($dir . '/*.mail') ?: []);
    }
}
