<?php

declare(strict_types=1);

namespace Wayfinder\Mail;

interface Mailer
{
    public function send(MailMessage $message): void;
}
