<?php

namespace App\Services\Notifications;

use App\Models\NotificationDelivery;
use App\Models\WhatsAppContact;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WhatsAppWebhookService
{
    public function verifySignature(string $payload, string $signature): bool
    {
        $secret = config('services.whatsapp.app_secret');
        if (! is_string($secret) || $secret === '' || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }
        $expected = 'sha256='.hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    /** @param array<string, mixed> $payload */
    public function handle(array $payload): void
    {
        foreach (($payload['entry'] ?? []) as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                if (! is_array($change) || ! is_array($change['value'] ?? null)) {
                    continue;
                }
                $value = $change['value'];
                foreach (($value['statuses'] ?? []) as $status) {
                    if (is_array($status)) {
                        $this->handleStatus($status);
                    }
                }
                foreach (($value['messages'] ?? []) as $message) {
                    if (is_array($message)) {
                        $this->handleInboundMessage($message);
                    }
                }
            }
        }
    }

    /** @param array<string, mixed> $status */
    private function handleStatus(array $status): void
    {
        $messageId = $status['id'] ?? null;
        $providerStatus = $status['status'] ?? null;
        if (! is_string($messageId) || ! is_string($providerStatus)) {
            return;
        }
        $timestamp = (string) ($status['timestamp'] ?? '');
        $delivery = NotificationDelivery::query()->where('provider_message_id', $messageId)->first();
        if (! $delivery) {
            return;
        }
        $eventKey = hash('sha256', implode('|', [$messageId, $providerStatus, $timestamp]));
        if (! $this->claimEvent($eventKey, 'status')) {
            return;
        }
        $priority = ['accepted' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
        $currentPriority = $priority[$delivery->provider_status ?? 'accepted'] ?? 0;
        $incomingPriority = $priority[$providerStatus] ?? 0;
        if ($incomingPriority < $currentPriority || (in_array($delivery->provider_status, ['failed', 'read'], true) && $providerStatus !== $delivery->provider_status)) {
            return;
        }

        $providerErrorCode = data_get($status, 'errors.0.code');
        $providerErrorCode = is_scalar($providerErrorCode) ? substr((string) $providerErrorCode, 0, 64) : null;
        $changes = ['provider_status' => substr($providerStatus, 0, 32)];
        if (in_array($providerStatus, ['sent', 'delivered', 'read'], true)) {
            $changes['status'] = $providerStatus;
            $changes['provider_error_code'] = null;
            $changes['last_error'] = null;
            $changes['next_attempt_at'] = null;
            $changes['sent_at'] = $delivery->sent_at ?? now();
        } elseif ($providerStatus === 'failed') {
            $changes['status'] = 'failed';
            $changes['provider_error_code'] = $providerErrorCode;
            $changes['last_error'] = $providerErrorCode !== null ? 'WhatsApp provider reported a delivery failure (code '.$providerErrorCode.').' : 'WhatsApp provider reported a delivery failure.';
            $changes['next_attempt_at'] = null;
        } else {
            return;
        }

        $delivery->forceFill($changes)->save();
    }

    /** @param array<string, mixed> $message */
    private function handleInboundMessage(array $message): void
    {
        $messageId = $message['id'] ?? null;
        $from = $message['from'] ?? null;
        if (! is_string($messageId) || ! is_string($from) || ! is_array($message['text'] ?? null)) {
            return;
        }
        $body = trim((string) ($message['text']['body'] ?? ''));
        $keyword = Str::upper($body);
        $optOutKeywords = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'PARAR', 'OPRIRE', 'DEZABONARE', 'NU MAI TRIMITE'];
        if (! in_array($keyword, $optOutKeywords, true)) {
            return;
        }
        $eventKey = hash('sha256', 'inbound-optout|'.$messageId);
        if (! $this->claimEvent($eventKey, 'opt_out')) {
            return;
        }

        $phone = PhoneNumber::normalize($from);
        if ($phone === '') {
            return;
        }
        WhatsAppContact::recordOptOut($phone, 'whatsapp_reply');
        NotificationDelivery::query()->where('channel', 'whatsapp')->whereIn('status', ['pending', 'queued'])
            ->where(function ($query) use ($phone): void {
                $normalized = $phone;
                $query->whereHas('booking', fn ($booking) => $booking->where('customer_phone', $normalized))
                    ->orWhereHas('entitlement', fn ($entitlement) => $entitlement->where('customer_phone', $normalized));
            })->update(['status' => 'skipped', 'next_attempt_at' => null, 'last_error' => 'Customer opted out of WhatsApp service messages.']);
    }

    private function claimEvent(string $key, string $type): bool
    {
        return DB::table('whatsapp_webhook_events')->insertOrIgnore([
            'event_key' => $key,
            'event_type' => $type,
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }
}
