<?php

namespace App\Services\Notifications;

use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenWaMessageReconciliationService
{
    /** @return array{status:string,provider_status:string,attempts:int,accepted_at:?string,created_at:?string,updated_at:?string} */
    public function reconcile(NotificationDelivery $delivery): array
    {
        if ($delivery->channel !== 'whatsapp' || $delivery->provider !== 'openwa' || $delivery->status !== 'ambiguous') {
            throw new RuntimeException('Only ambiguous OpenWA WhatsApp deliveries can be reconciled.');
        }
        $config = config('services.whatsapp.openwa', []);
        $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        $token = $config['service_token'] ?? null;
        if (! str_starts_with($baseUrl, 'https://') || ! is_string($token) || strlen($token) < 32) {
            throw new RuntimeException('OpenWA reconciliation is not configured.');
        }
        $key = 'easyenglish-delivery-'.$delivery->id;
        $response = Http::acceptJson()->withToken($token)->connectTimeout(5)->timeout(10)->retry(0)
            ->get($baseUrl.'/v1/messages/'.rawurlencode($key));
        if ($response->status() === 404) {
            $delivery->update(['status' => 'failed', 'next_attempt_at' => null, 'last_error' => 'OpenWA has no submission record; a controlled retry is safe.', 'provider_attempts' => $this->updateLastAttempt($delivery, 'failed', ['reason' => 'not_found'])]);

            return $this->result('failed', 'not_found', ['attempts' => count($delivery->provider_attempts ?? [])]);
        }
        if (! $response->successful()) {
            throw new RuntimeException('OpenWA message status could not be retrieved (HTTP '.$response->status().').');
        }
        $status = $response->json('status');
        if ($status === 'accepted' && is_string($response->json('providerMessageId'))) {
            $delivery->update([
                'status' => 'sent', 'provider_message_id' => $response->json('providerMessageId'),
                'provider_status' => 'accepted', 'sent_at' => $delivery->sent_at ?? now(),
                'next_attempt_at' => null, 'last_error' => null,
                'provider_attempts' => $this->updateLastAttempt($delivery, 'accepted', ['message_id' => $response->json('providerMessageId')]),
            ]);

            return $this->result('sent', 'accepted', $response->json());
        }
        if ($status === 'failed') {
            $delivery->update(['status' => 'failed', 'next_attempt_at' => null, 'last_error' => 'OpenWA confirmed that the message was not accepted; a controlled retry is safe.', 'provider_attempts' => $this->updateLastAttempt($delivery, 'failed')]);

            return $this->result('failed', 'failed', $response->json());
        }

        return $this->result('ambiguous', is_string($status) ? $status : 'unknown', $response->json());
    }

    /**
     * @param  array<string, mixed>  $provider
     * @return array{status:string,provider_status:string,attempts:int,accepted_at:?string,created_at:?string,updated_at:?string}
     */
    private function result(string $status, string $providerStatus, array $provider): array
    {
        return [
            'status' => $status,
            'provider_status' => $providerStatus,
            'attempts' => max(0, (int) ($provider['attempts'] ?? 0)),
            'accepted_at' => is_string($provider['acceptedAt'] ?? null) ? $provider['acceptedAt'] : null,
            'created_at' => is_string($provider['createdAt'] ?? null) ? $provider['createdAt'] : null,
            'updated_at' => is_string($provider['updatedAt'] ?? null) ? $provider['updatedAt'] : null,
        ];
    }

    /**
     * @param  array<string, scalar|null>  $details
     * @return list<array<string, scalar|null>>
     */
    private function updateLastAttempt(NotificationDelivery $delivery, string $status, array $details = []): array
    {
        $attempts = $delivery->provider_attempts ?? [];
        if ($attempts !== []) {
            $last = array_key_last($attempts);
            $attempts[$last] = array_merge($attempts[$last], $details, [
                'status' => $status,
                'completed_at' => now()->toIso8601String(),
            ]);
        }

        return $attempts;
    }
}
