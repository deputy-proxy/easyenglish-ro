<?php

namespace App\Contracts\Notifications;

use App\Data\Notifications\WhatsAppTemplateMessage;
use App\Data\Notifications\WhatsAppTextMessage;

interface WhatsAppGateway
{
    /** @return array{message_id:string,status:string} */
    public function sendTemplate(WhatsAppTemplateMessage $message): array;

    /** @return array{message_id:string,status:string} */
    public function sendText(WhatsAppTextMessage $message): array;
}
