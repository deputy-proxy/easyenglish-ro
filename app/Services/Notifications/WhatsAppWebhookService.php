<?php

namespace App\Services\Notifications;

use App\Models\ContactVerificationChallenge;
use App\Models\Message;
use App\Models\NotificationDelivery;
use App\Models\WhatsAppContact;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
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

    /**
     * @param  array<string, mixed>  $message
     * @return array{messages_persisted: int, messages_duplicate: int, messages_ignored: int}
     */
    public function handleOpenWaInbound(array $message): array
    {
        $id = $message['id'] ?? null;
        $from = $message['from'] ?? null;
        if (! is_string($id) || ! is_string($from)) {
            return ['messages_persisted' => 0, 'messages_duplicate' => 0, 'messages_ignored' => 1];
        }
        $normalized = [
            'id' => 'openwa:'.$id,
            'from' => $from,
            'type' => 'text',
            'text' => ['body' => is_string($message['text'] ?? null) ? $message['text'] : ''],
            'timestamp' => is_scalar($message['timestamp'] ?? null) ? (string) $message['timestamp'] : null,
            'context' => array_filter([
                'openwa_sender_id' => is_string($message['senderId'] ?? null) ? $message['senderId'] : null,
                'phone_resolution' => is_string($message['senderId'] ?? null)
                    ? (str_ends_with(strtolower($from), '@lid') ? 'unresolved' : 'resolved')
                    : null,
            ], static fn (mixed $value): bool => $value !== null),
        ];
        $result = $this->handleInboundMessage($normalized, ['display_phone_number' => $message['to'] ?? null]);

        return [
            'messages_persisted' => $result === 'persisted' ? 1 : 0,
            'messages_duplicate' => $result === 'duplicate' ? 1 : 0,
            'messages_ignored' => $result === 'ignored' ? 1 : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{entries: int, changes: int, statuses_received: int, messages_received: int, messages_persisted: int, messages_duplicate: int, messages_ignored: int}
     */
    public function handle(array $payload): array
    {
        $summary = [
            'entries' => 0,
            'changes' => 0,
            'statuses_received' => 0,
            'messages_received' => 0,
            'messages_persisted' => 0,
            'messages_duplicate' => 0,
            'messages_ignored' => 0,
        ];

        foreach (($payload['entry'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $summary['entries']++;
            foreach (($entry['changes'] ?? []) as $change) {
                if (! is_array($change) || ! is_array($change['value'] ?? null)) {
                    continue;
                }
                $summary['changes']++;
                $value = $change['value'];
                $metadata = is_array($value['metadata'] ?? null) ? $value['metadata'] : [];
                foreach (($value['statuses'] ?? []) as $status) {
                    if (is_array($status)) {
                        $summary['statuses_received']++;
                        $this->handleStatus($status);
                    }
                }
                foreach (($value['messages'] ?? []) as $message) {
                    if (! is_array($message)) {
                        continue;
                    }
                    $summary['messages_received']++;
                    $result = $this->handleInboundMessage($message, $metadata);
                    if ($result === 'persisted') {
                        $summary['messages_persisted']++;
                    } elseif ($result === 'duplicate') {
                        $summary['messages_duplicate']++;
                    } else {
                        $summary['messages_ignored']++;
                    }
                }
            }
        }

        return $summary;
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
        $callbackData = $status['biz_opaque_callback_data'] ?? null;
        $challenge = ContactVerificationChallenge::query()->where('phone_provider_message_id', $messageId)->first();
        if (! $challenge && is_string($callbackData)
            && preg_match('/^contact-verification-([0-9a-fA-F-]{36})$/', $callbackData, $matches) === 1) {
            $challenge = ContactVerificationChallenge::query()->whereKey($matches[1])->first();
        }
        if ($challenge) {
            $this->handleVerificationStatus($challenge, $status, $messageId, $providerStatus, $timestamp);

            return;
        }

        $delivery = NotificationDelivery::query()->where('provider_message_id', $messageId)->where('provider', 'meta')->first();
        if (! $delivery) {
            if (is_string($callbackData) && preg_match('/^easyenglish-delivery-(\d+)$/', $callbackData, $matches) === 1) {
                $delivery = NotificationDelivery::query()->whereKey((int) $matches[1])
                    ->where('channel', 'whatsapp')->where('provider', 'meta')->first();
                if ($delivery) {
                    $delivery->forceFill(['provider_message_id' => $messageId])->save();
                }
            }
        }
        if (! $delivery || $delivery->provider !== 'meta') {
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

        $providerAttempts = $delivery->provider_attempts ?? [];
        for ($index = count($providerAttempts) - 1; $index >= 0; $index--) {
            if (($providerAttempts[$index]['provider'] ?? null) !== 'meta') {
                continue;
            }
            $attemptMessageId = $providerAttempts[$index]['message_id'] ?? null;
            if (is_string($attemptMessageId) && $attemptMessageId !== $messageId) {
                continue;
            }
            $providerAttempts[$index] = array_merge($providerAttempts[$index], [
                'status' => $providerStatus,
                'completed_at' => now()->toIso8601String(),
                'message_id' => $messageId,
            ]);
            $changes['provider_attempts'] = $providerAttempts;
            break;
        }
        $delivery->forceFill($changes)->save();
    }

    /** @param array<string, mixed> $status */
    private function handleVerificationStatus(ContactVerificationChallenge $challenge, array $status, string $messageId, string $providerStatus, string $timestamp): void
    {
        $eventKey = hash('sha256', implode('|', ['verification', $messageId, $providerStatus, $timestamp]));
        if (! $this->claimEvent($eventKey, 'verification_status')) {
            return;
        }

        $priority = ['accepted' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
        $currentPriority = $priority[$challenge->phone_provider_status ?? 'accepted'] ?? 0;
        $incomingPriority = $priority[$providerStatus] ?? null;
        if ($incomingPriority === null || $incomingPriority < $currentPriority
            || (in_array($challenge->phone_provider_status, ['failed', 'read'], true)
                && $providerStatus !== $challenge->phone_provider_status)) {
            return;
        }

        $errorCode = data_get($status, 'errors.0.code');
        $challenge->forceFill([
            'phone_provider_message_id' => $messageId,
            'phone_provider_status' => $providerStatus,
            'phone_provider_error_code' => $providerStatus === 'failed' && is_scalar($errorCode)
                ? substr((string) $errorCode, 0, 64)
                : null,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $metadata
     */
    private function handleInboundMessage(array $message, array $metadata): string
    {
        $messageId = $message['id'] ?? null;
        $from = $message['from'] ?? null;
        if (! is_string($messageId) || $messageId === '' || ! is_string($from)) {
            return 'ignored';
        }

        $senderId = trim($from);
        $phone = PhoneNumber::normalize($senderId);
        $isOpenWaLinkedId = str_starts_with($messageId, 'openwa:') && str_ends_with(strtolower($senderId), '@lid');
        $phoneResolution = $isOpenWaLinkedId ? 'unresolved' : null;

        if ($isOpenWaLinkedId) {
            $phone = null;
        } elseif ($phone === '' || str_contains($senderId, '@')) {
            return 'ignored';
        }

        $resolvedLinkedId = data_get($message, 'context.openwa_sender_id');
        if (is_string($resolvedLinkedId) && str_ends_with(strtolower($resolvedLinkedId), '@lid') && $phone !== null) {
            $this->reconcileLinkedIdContact($resolvedLinkedId, $phone);
            $phoneResolution = 'resolved';
        }

        $storedSender = $phone ?? $senderId;

        $type = is_string($message['type'] ?? null) ? $message['type'] : 'unknown';
        $body = $this->extractBody($message, $type);
        $providerTimestamp = is_scalar($message['timestamp'] ?? null) ? (string) $message['timestamp'] : null;
        $receivedAt = null;
        if ($providerTimestamp !== null && ctype_digit($providerTimestamp)) {
            $receivedAt = CarbonImmutable::createFromTimestamp((int) $providerTimestamp, 'UTC');
        }

        $eventKey = hash('sha256', 'inbound-message|'.$messageId);
        $persisted = DB::transaction(function () use ($eventKey, $messageId, $storedSender, $senderId, $phoneResolution, $metadata, $type, $body, $providerTimestamp, $receivedAt, $message): bool {
            if (! $this->claimEvent($eventKey, 'inbound_message')) {
                return false;
            }

            Message::query()->create([
                'provider_message_id' => $messageId,
                'channel' => 'whatsapp',
                'direction' => 'inbound',
                'from_phone' => $storedSender,
                'to_phone' => is_string($metadata['display_phone_number'] ?? null)
                    ? PhoneNumber::normalize($metadata['display_phone_number'])
                    : null,
                'type' => substr($type, 0, 32),
                'body' => $body,
                'provider_timestamp' => $providerTimestamp,
                'received_at' => $receivedAt,
                'context' => array_filter([
                    ...(is_array($message['context'] ?? null) ? $message['context'] : []),
                    'openwa_sender_id' => data_get($message, 'context.openwa_sender_id') ?? ($senderId !== $storedSender ? $senderId : null),
                    'phone_resolution' => $phoneResolution,
                ], static fn (mixed $value): bool => $value !== null),
            ]);

            return true;
        });

        if (! $persisted) {
            return 'duplicate';
        }

        if ($phone === null || $type !== 'text' || $body === null) {
            return 'persisted';
        }

        $keyword = Str::upper(trim($body));
        $optOutKeywords = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'PARAR', 'OPRIRE', 'DEZABONARE', 'NU MAI TRIMITE'];
        if (! in_array($keyword, $optOutKeywords, true)) {
            return 'persisted';
        }

        $optOutEventKey = hash('sha256', 'inbound-optout|'.$messageId);
        if (! $this->claimEvent($optOutEventKey, 'opt_out')) {
            return 'persisted';
        }

        WhatsAppContact::recordOptOut($phone, 'whatsapp_reply');
        NotificationDelivery::query()->where('channel', 'whatsapp')->whereIn('status', ['pending', 'queued'])
            ->where(function ($query) use ($phone): void {
                $query->whereHas('booking', fn ($booking) => $booking->where('customer_phone', $phone))
                    ->orWhereHas('entitlement', fn ($entitlement) => $entitlement->where('customer_phone', $phone))
                    ->orWhereHas('recipientUser', fn ($recipient) => $recipient->where('phone', $phone));
            })->update(['status' => 'skipped', 'next_attempt_at' => null, 'last_error' => 'Customer opted out of WhatsApp service messages.']);

        return 'persisted';
    }

    private function reconcileLinkedIdContact(string $linkedId, string $phone): void
    {
        $linkedIdDigits = preg_replace('/\D+/', '', substr($linkedId, 0, -4)) ?? '';
        if ($linkedIdDigits === '') {
            return;
        }

        $legacyPhone = '+'.$linkedIdDigits;
        if ($legacyPhone === $phone) {
            return;
        }

        DB::transaction(function () use ($legacyPhone, $phone): void {
            $legacy = WhatsAppContact::query()->where('phone', $legacyPhone)->lockForUpdate()->first();
            if (! $legacy) {
                return;
            }

            $contact = WhatsAppContact::query()->where('phone', $phone)->lockForUpdate()->first();
            if (! $contact) {
                $legacy->update(['phone' => $phone]);

                return;
            }

            $changes = [];
            if ($legacy->consented_at !== null && ($contact->consented_at === null || strtotime((string) $legacy->consented_at) > strtotime((string) $contact->consented_at))) {
                $changes['consented_at'] = $legacy->consented_at;
                $changes['consent_source'] = $legacy->consent_source;
            }
            if ($legacy->opted_out_at !== null && ($contact->opted_out_at === null || strtotime((string) $legacy->opted_out_at) > strtotime((string) $contact->opted_out_at))) {
                $changes['opted_out_at'] = $legacy->opted_out_at;
                $changes['opt_out_source'] = $legacy->opt_out_source;
            }
            if ($contact->consented_at !== null && $contact->opted_out_at !== null && strtotime((string) $contact->consented_at) > strtotime((string) $contact->opted_out_at)) {
                $changes['opted_out_at'] = null;
                $changes['opt_out_source'] = null;
            }
            if ($changes !== []) {
                $contact->forceFill($changes)->save();
            }
            $legacy->delete();
        });
    }

    /** @param array<string, mixed> $message */
    private function extractBody(array $message, string $type): ?string
    {
        $value = match ($type) {
            'text' => data_get($message, 'text.body'),
            'button' => data_get($message, 'button.text'),
            'interactive' => data_get($message, 'interactive.button_reply.title')
                ?? data_get($message, 'interactive.list_reply.title'),
            'location' => $this->formatLocation($message),
            'contacts' => 'Contact shared',
            default => null,
        };

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @param array<string, mixed> $message */
    private function formatLocation(array $message): ?string
    {
        $latitude = data_get($message, 'location.latitude');
        $longitude = data_get($message, 'location.longitude');
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        return 'Location: '.$latitude.', '.$longitude;
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
