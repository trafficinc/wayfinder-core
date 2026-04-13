<?php

declare(strict_types=1);

namespace Wayfinder\Mail;

final class MailMessage
{
    public function __construct(
        private readonly string $to,
        private readonly string $subject,
        private readonly string $text,
        private readonly ?string $html = null,
    ) {
    }

    public function to(): string
    {
        return $this->to;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function html(): ?string
    {
        return $this->html;
    }
}
