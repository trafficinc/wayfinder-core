<?php

declare(strict_types=1);

namespace Wayfinder\Mail;

final class FileMailer implements Mailer
{
    public function __construct(
        private readonly string $path,
    ) {
    }

    public function send(MailMessage $message): void
    {
        if (! is_dir($this->path) && ! @mkdir($concurrentDirectory = $this->path, 0777, true) && ! is_dir($concurrentDirectory)) {
            throw new \RuntimeException(sprintf('Unable to create mail directory [%s].', $this->path));
        }

        $payload = json_encode([
            'to' => $message->to(),
            'subject' => $message->subject(),
            'text' => $message->text(),
            'html' => $message->html(),
            'sent_at' => date('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new \RuntimeException('Unable to encode mail payload.');
        }

        $file = sprintf('%s/%s_%s.mail', rtrim($this->path, '/'), date('YmdHis'), bin2hex(random_bytes(4)));
        file_put_contents($file, $payload);
    }
}
