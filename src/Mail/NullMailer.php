<?php

declare(strict_types=1);

namespace Wayfinder\Mail;

final class NullMailer implements Mailer
{
    public function send(MailMessage $message): void
    {
    }
}
