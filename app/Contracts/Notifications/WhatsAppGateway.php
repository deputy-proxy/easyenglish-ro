<?php

namespace App\Contracts\Notifications;

interface WhatsAppGateway
{
    /** @param list<array{type:string,parameters:list<array{type:string,text:string}>}> $components
     * @return array{message_id:string,status:string}
     */
    public function sendTemplate(string $recipient, string $template, string $language, array $components, string $callbackData): array;
}
