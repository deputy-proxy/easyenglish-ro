<?php

namespace Tests\Support;

use App\Contracts\Notifications\WhatsAppGateway;
use App\Data\Notifications\WhatsAppTemplateMessage;
use App\Data\Notifications\WhatsAppTextMessage;
use Illuminate\Support\Str;

class FakeWhatsAppGateway implements WhatsAppGateway
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function sendTemplate(WhatsAppTemplateMessage $message): array
    {
        $template = config('services.whatsapp.templates.'.$message->template, $message->template);
        $components = [];
        if ($message->bodyParameters !== []) {
            $components[] = ['type' => 'body', 'parameters' => array_map(static fn (string $value): array => ['type' => 'text', 'text' => $value], $message->bodyParameters)];
        }
        foreach ($message->urlButtons as $button) {
            $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => $button['index'], 'parameters' => array_map(static fn (string $value): array => ['type' => 'text', 'text' => $value], $button['parameters'])];
        }
        $this->sent[] = ['recipient' => $message->recipient, 'template' => $template, 'template_key' => $message->template, 'language' => $message->language, 'components' => $components, 'callback_data' => $message->callbackData];

        return ['message_id' => 'wamid.'.Str::uuid(), 'status' => 'accepted'];
    }

    public function sendText(WhatsAppTextMessage $message): array
    {
        $this->sent[] = ['recipient' => $message->recipient, 'text' => $message->text, 'callback_data' => $message->callbackData];

        return ['message_id' => 'wamid.'.Str::uuid(), 'status' => 'accepted'];
    }
}
