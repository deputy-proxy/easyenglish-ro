<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\WhatsAppGateway;
use App\Data\Notifications\WhatsAppTemplateMessage;
use App\Data\Notifications\WhatsAppTextMessage;
use App\Exceptions\WhatsAppDeliveryAmbiguousException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenWaWhatsAppGateway implements WhatsAppGateway
{
    public function __construct(private readonly OpenWaMessageRenderer $renderer) {}

    public function sendTemplate(WhatsAppTemplateMessage $message): array
    {
        return $this->send($message->recipient, $this->renderer->render($message), $message->callbackData);
    }

    public function sendText(WhatsAppTextMessage $message): array
    {
        return $this->send($message->recipient, $message->text, $message->callbackData);
    }

    /** @return array{message_id: string, status: string} */
    private function send(string $recipient, string $text, string $idempotencyKey): array
    {
        $config = config('services.whatsapp.openwa', []);
        $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        $token = $config['service_token'] ?? null;
        if (! ($config['enabled'] ?? false) || $baseUrl === '' || ! is_string($token) || strlen($token) < 32) {
            throw new RuntimeException('OpenWA messaging is disabled or not fully configured.');
        }
        if (! str_starts_with($baseUrl, 'https://')) {
            throw new RuntimeException('OpenWA messaging requires an HTTPS service URL.');
        }
        try {
            $response = Http::acceptJson()->asJson()->withToken($token)
                ->connectTimeout(5)->timeout(max(5, (int) ($config['timeout'] ?? 15)))->retry(0)
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post($baseUrl.'/v1/messages', ['to' => $recipient, 'text' => $text]);
        } catch (ConnectionException $exception) {
            throw $exception;
        }
        if ($response->status() === 202 || $response->serverError()) {
            throw new WhatsAppDeliveryAmbiguousException('OpenWA message submission outcome is ambiguous and requires reconciliation before retry.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('OpenWA messaging provider rejected the request (HTTP '.$response->status().').');
        }
        $messageId = $response->json('providerMessageId');
        $status = $response->json('status');
        if (! is_string($messageId) || $messageId === '' || ! in_array($status, ['accepted', 'sent'], true)) {
            throw new WhatsAppDeliveryAmbiguousException('OpenWA messaging provider returned an incomplete acknowledgement.');
        }

        return ['message_id' => $messageId, 'status' => $status];
    }
}
