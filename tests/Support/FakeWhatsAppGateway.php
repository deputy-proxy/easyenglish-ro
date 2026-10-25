<?php

namespace Tests\Support;

use App\Contracts\Notifications\WhatsAppGateway;
use Illuminate\Support\Str;

class FakeWhatsAppGateway implements WhatsAppGateway
{
    /** @var list<array{recipient:string,template:string,language:string,components:list<array{type:string,parameters:list<array{type:string,text:string}>}>,callback_data:string}> */
    public array $sent = [];

    public function sendTemplate(string $recipient, string $template, string $language, array $components, string $callbackData): array
    {
        $this->sent[] = ['recipient' => $recipient, 'template' => $template, 'language' => $language, 'components' => $components, 'callback_data' => $callbackData];

        return ['message_id' => 'wamid.'.Str::uuid(), 'status' => 'accepted'];
    }
}
