<?php

namespace App\Data\Notifications;

final readonly class WhatsAppTextMessage
{
    public function __construct(
        public string $recipient,
        public string $text,
        public string $callbackData,
    ) {}
}
