<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\WhatsAppGateway;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MetaWhatsAppCloudApiGateway implements WhatsAppGateway
{
    public function sendTemplate(string $recipient, string $template, string $language, array $components, string $callbackData): array
    {
        $token = config('services.whatsapp.access_token');
        $phoneNumberId = config('services.whatsapp.phone_number_id');
        $version = config('services.whatsapp.api_version');
        if (! (bool) config('services.whatsapp.enabled') || ! is_string($token) || $token === ''
            || ! is_string($phoneNumberId) || $phoneNumberId === '' || ! is_string($version) || ! preg_match('/^v[0-9]+\\.[0-9]+$/', $version)) {
            throw new RuntimeException('WhatsApp Cloud API is disabled or not fully configured.');
        }
        if (! preg_match('/^[a-z0-9_]{1,128}$/', $template)) {
            throw new RuntimeException('WhatsApp template name is not configured correctly.');
        }

        $response = $this->client($token)->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => preg_replace('/\\D+/', '', $recipient),
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $language],
                'components' => $components,
            ],
            'biz_opaque_callback_data' => $callbackData,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('WhatsApp Cloud API rejected the template request (HTTP '.$response->status().').');
        }

        $messageId = $response->json('messages.0.id');
        if (! is_string($messageId) || $messageId === '') {
            throw new RuntimeException('WhatsApp Cloud API response did not include a message ID.');
        }

        return ['message_id' => $messageId, 'status' => 'accepted'];
    }

    private function client(string $token): PendingRequest
    {
        return Http::acceptJson()->asJson()->withToken($token)->connectTimeout(5)->timeout(15)->retry(0);
    }
}
