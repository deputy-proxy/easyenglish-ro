<?php

namespace App\Contracts\Notifications;

interface WhatsAppGateway
{
    /**
     * @param list<
     *     array{type:'body',parameters:list<array{type:'text',text:string}>}
     *     | array{type:'button',sub_type:'copy_code',index:string,parameters:list<array{type:'payload',payload:string}>}
     * > $components
     * @return array{message_id:string,status:string}
     */
    public function sendTemplate(string $recipient, string $template, string $language, array $components, string $callbackData): array;
}
