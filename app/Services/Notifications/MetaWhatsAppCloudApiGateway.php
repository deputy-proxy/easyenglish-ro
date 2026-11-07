<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\WhatsAppGateway;
use App\Data\Notifications\WhatsAppTemplateMessage;
use App\Data\Notifications\WhatsAppTextMessage;
use App\Exceptions\WhatsAppDeliveryAmbiguousException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MetaWhatsAppCloudApiGateway implements WhatsAppGateway
{
    public function sendTemplate(WhatsAppTemplateMessage $message): array
    {
        [$token, $phoneNumberId, $version] = $this->configuration();
        $templateName = config('services.whatsapp.templates.'.$message->template);
        if (! is_string($templateName) || ! preg_match('/^[a-z0-9_]{1,128}$/', $templateName)) {
            throw new RuntimeException('WhatsApp template name is not configured correctly.');
        }
        $components = [];
        if ($message->bodyParameters !== []) {
            $components[] = ['type' => 'body', 'parameters' => array_map(static fn (string $value): array => ['type' => 'text', 'text' => $value], $message->bodyParameters)];
        }
        foreach ($message->urlButtons as $button) {
            $components[] = [
                'type' => 'button', 'sub_type' => 'url', 'index' => $button['index'],
                'parameters' => array_map(static fn (string $value): array => ['type' => 'text', 'text' => $value], $button['parameters']),
            ];
        }

        $response = $this->client($token)->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp', 'recipient_type' => 'individual',
            'to' => preg_replace('/\D+/', '', $message->recipient), 'type' => 'template',
            'template' => ['name' => $templateName, 'language' => ['code' => $message->language], 'components' => $components],
            'biz_opaque_callback_data' => $message->callbackData,
        ]);

        return $this->result($response);
    }

    public function sendText(WhatsAppTextMessage $message): array
    {
        if (! (bool) config('services.whatsapp.allow_free_form')) {
            throw new RuntimeException('Meta free-form WhatsApp text is disabled unless a valid customer-service window has been established.');
        }
        if (trim($message->text) === '' || mb_strlen($message->text) > 4096) {
            throw new RuntimeException('WhatsApp text messages must contain between 1 and 4096 characters.');
        }
        [$token, $phoneNumberId, $version] = $this->configuration();
        $response = $this->client($token)->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp', 'recipient_type' => 'individual',
            'to' => preg_replace('/\D+/', '', $message->recipient), 'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $message->text],
            'biz_opaque_callback_data' => $message->callbackData,
        ]);

        return $this->result($response);
    }

    /** @return array{string,string,string} */
    private function configuration(): array
    {
        $token = config('services.whatsapp.access_token');
        $phoneNumberId = config('services.whatsapp.phone_number_id');
        $version = config('services.whatsapp.api_version');
        if (! (bool) config('services.whatsapp.enabled') || ! is_string($token) || $token === ''
            || ! is_string($phoneNumberId) || $phoneNumberId === '' || ! is_string($version) || ! preg_match('/^v[0-9]+\.[0-9]+$/', $version)) {
            throw new RuntimeException('WhatsApp Cloud API is disabled or not fully configured.');
        }

        return [$token, $phoneNumberId, $version];
    }

    /** @return array{message_id: string, status: string} */
    private function result(Response $response): array
    {
        if (! $response->successful()) {
            if ($response->serverError()) {
                throw new WhatsAppDeliveryAmbiguousException('Meta returned a server error after WhatsApp message submission.');
            }
            $error = $response->json('error', []);
            $error = is_array($error) ? $error : [];
            $details = array_filter([
                'code' => isset($error['code']) && is_scalar($error['code']) ? (string) $error['code'] : null,
                'type' => isset($error['type']) && is_string($error['type']) ? $error['type'] : null,
                'message' => isset($error['message']) && is_string($error['message']) ? mb_substr($error['message'], 0, 300) : null,
                'details' => isset($error['error_data']['details']) && is_string($error['error_data']['details']) ? mb_substr($error['error_data']['details'], 0, 300) : null,
            ]);
            $description = $details === [] ? 'no error details returned' : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            throw new RuntimeException('WhatsApp Cloud API rejected the message (HTTP '.$response->status().'; '.$description.').');
        }
        $messageId = $response->json('messages.0.id');
        if (! is_string($messageId) || $messageId === '') {
            throw new WhatsAppDeliveryAmbiguousException('WhatsApp Cloud API response did not include a message ID.');
        }

        return ['message_id' => $messageId, 'status' => 'accepted'];
    }

    private function client(string $token): PendingRequest
    {
        return Http::acceptJson()->asJson()->withToken($token)->connectTimeout(5)->timeout(15)->retry(0);
    }
}
