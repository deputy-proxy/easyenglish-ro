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
            $error = $response->json('error', []);
            $error = is_array($error) ? $error : [];
            $details = array_filter([
                'code' => isset($error['code']) && is_scalar($error['code']) ? (string) $error['code'] : null,
                'type' => isset($error['type']) && is_string($error['type']) ? $error['type'] : null,
                'message' => isset($error['message']) && is_string($error['message']) ? mb_substr($error['message'], 0, 300) : null,
                'details' => isset($error['error_data']['details']) && is_string($error['error_data']['details'])
                    ? mb_substr($error['error_data']['details'], 0, 300)
                    : null,
            ]);
            $description = $details === [] ? 'no error details returned' : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            throw new RuntimeException('WhatsApp Cloud API rejected the template request (HTTP '.$response->status().'; '.$description.').');
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
