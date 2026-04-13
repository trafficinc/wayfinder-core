<?php

declare(strict_types=1);

namespace Wayfinder\Mail;

final class MailFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public function make(array $config): Mailer
    {
        return match ($config['driver'] ?? 'null') {
            'file' => new FileMailer((string) ($config['path'] ?? sys_get_temp_dir() . '/wayfinder-mail')),
            default => new NullMailer(),
        };
    }
}
