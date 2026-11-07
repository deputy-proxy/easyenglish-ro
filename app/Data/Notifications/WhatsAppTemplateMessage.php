<?php

namespace App\Data\Notifications;

final readonly class WhatsAppTemplateMessage
{
    /**
     * @param  list<string>  $bodyParameters
     * @param  list<array{index: string, parameters: list<string>}>  $urlButtons
     */
    public function __construct(
        public string $recipient,
        public string $template,
        public string $language,
        public array $bodyParameters,
        public array $urlButtons,
        public string $callbackData,
    ) {}
}
