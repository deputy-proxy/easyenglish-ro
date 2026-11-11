<?php

namespace App\Jobs;

use App\Contracts\Notifications\WhatsAppNotificationGateway;
use App\Data\Notifications\WhatsAppTextMessage;
use App\Exceptions\NotificationNoLongerEligibleException;
use App\Exceptions\WhatsAppDeliveryAmbiguousException;
use App\Exceptions\WhatsAppDeliveryRejectedException;
use App\Mail\TransactionalNotificationEmail;
use App\Models\NotificationDelivery;
use App\Models\WhatsAppContact;
use App\Services\Notifications\NotificationMessageFactory;
use App\Services\Notifications\WhatsAppMessageFactory;
use App\Services\Notifications\WhatsAppProviderConfiguration;
use App\Support\PhoneNumber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendNotificationDelivery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 25;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(NotificationMessageFactory $messages, WhatsAppMessageFactory $whatsappMessages, WhatsAppNotificationGateway $whatsapp): void
    {
        $claimed = DB::transaction(function (): bool {
            $delivery = NotificationDelivery::query()->lockForUpdate()->find($this->deliveryId);
            if (! $delivery || ! in_array($delivery->channel, ['email', 'whatsapp'], true) || $delivery->status !== 'queued') {
                return false;
            }

            $attributes = [
                'status' => 'sending',
                'attempts' => $delivery->attempts + 1,
                'last_error' => null,
            ];
            if ($delivery->channel === 'whatsapp') {
                $provider = 'openwa';
                $providerAttempts = $delivery->provider_attempts ?? [];
                $providerAttempts[] = ['provider' => $provider, 'attempt' => $delivery->attempts + 1, 'status' => 'sending', 'started_at' => now()->toIso8601String()];
                $attributes['provider'] = $provider;
                $attributes['provider_error_code'] = null;
                $attributes['provider_attempts'] = $providerAttempts;
            }
            $delivery->update($attributes);

            return true;
        }, attempts: 3);

        if (! $claimed) {
            return;
        }

        try {
            $delivery = NotificationDelivery::query()->with(['booking', 'entitlement', 'refund.payment.entitlement', 'recipientUser'])->findOrFail($this->deliveryId);
            if ($delivery->channel === 'whatsapp') {
                if (! app(WhatsAppProviderConfiguration::class)->isOpenWaMessagingConfigured()) {
                    throw new NotificationNoLongerEligibleException('OpenWA messaging is disabled or not fully configured.');
                }
                $provider = 'openwa';

                if ($delivery->recipient_type === 'teacher') {
                    $recipientPhone = $delivery->recipientUser?->phone;
                    if ($delivery->recipientUser === null || ! $delivery->recipientUser->isTeacher()
                        || $delivery->recipientUser->phone_verified_at === null) {
                        throw new NotificationNoLongerEligibleException('Teacher WhatsApp recipient is no longer eligible.');
                    }
                } else {
                    $recipientPhone = $delivery->booking->customer_phone
                        ?? $delivery->entitlement->customer_phone
                        ?? $delivery->refund->payment->entitlement->customer_phone;
                }
                try {
                    $normalizedRecipientPhone = PhoneNumber::normalize((string) $recipientPhone);
                } catch (\InvalidArgumentException) {
                    throw new NotificationNoLongerEligibleException('WhatsApp notification recipient phone number is invalid.');
                }
                $contact = WhatsAppContact::query()->where('phone', $normalizedRecipientPhone)->first();
                if ($contact === null || ! $contact->canReceiveServiceMessages()) {
                    throw new NotificationNoLongerEligibleException('Notification recipient has not opted in to WhatsApp service messages or has opted out.');
                }

                $messageData = $whatsappMessages->make($delivery);
                $result = $messageData instanceof WhatsAppTextMessage
                    ? $whatsapp->sendText($messageData)
                    : $whatsapp->sendTemplate($messageData);
                $current = NotificationDelivery::query()->findOrFail($this->deliveryId);
                $providerAttempts = $this->providerAttemptsWithStatus($current, 'accepted', ['message_id' => $result['message_id']]);
                try {
                    $current->update([
                        'provider_attempts' => $providerAttempts,
                        'status' => 'sent',
                        'sent_at' => now(),
                        'next_attempt_at' => null,
                        'last_error' => null,
                        'provider' => $provider,
                        'provider_message_id' => $result['message_id'],
                        'provider_status' => $result['status'],
                        'provider_error_code' => null,
                    ]);
                } catch (Throwable $exception) {
                    throw new WhatsAppDeliveryAmbiguousException('Provider accepted the message but Laravel could not persist the acknowledgement.', previous: $exception);
                }
            } else {
                $messageData = $messages->make($delivery);
                $recipient = $messageData['recipient'];
                unset($messageData['recipient']);
                Mail::to($recipient)->send(new TransactionalNotificationEmail($messageData));
                NotificationDelivery::query()->whereKey($this->deliveryId)->where('status', 'sending')->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'next_attempt_at' => null,
                    'last_error' => null,
                ]);
            }
        } catch (NotificationNoLongerEligibleException $exception) {
            $delivery = NotificationDelivery::query()->find($this->deliveryId);
            if ($delivery) {
                $attributes = [
                    'status' => 'skipped',
                    'next_attempt_at' => null,
                    'last_error' => $exception->getMessage(),
                ];
                if ($delivery->channel === 'whatsapp') {
                    $attributes['provider_attempts'] = $this->providerAttemptsWithStatus($delivery, 'skipped');
                }
                $delivery->update($attributes);
            }

            return;
        } catch (WhatsAppDeliveryRejectedException $exception) {
            $delivery = NotificationDelivery::query()->find($this->deliveryId);
            if ($delivery && $delivery->status === 'sending') {
                $delivery->update([
                    'status' => 'failed',
                    'next_attempt_at' => null,
                    'last_error' => 'WhatsApp delivery rejected ('.$exception->errorCode.').',
                    'provider_error_code' => $exception->errorCode,
                    'provider_attempts' => $this->providerAttemptsWithStatus(
                        $delivery,
                        'failed',
                        ['error_code' => $exception->errorCode],
                    ),
                ]);
                Log::warning('Transactional WhatsApp notification was rejected by the provider.', [
                    'notification_delivery_id' => $delivery->id,
                    'provider' => 'openwa',
                    'provider_error_code' => $exception->errorCode,
                ]);
            }

            return;
        } catch (Throwable $exception) {
            $delivery = NotificationDelivery::query()->find($this->deliveryId);
            if (! $delivery || $delivery->status !== 'sending') {
                return;
            }

            $attempts = (int) $delivery->attempts;
            $retrySeconds = [60, 300, 900, 3600, 14400];
            $ambiguous = $delivery->channel === 'whatsapp'
                && ($exception instanceof ConnectionException || $exception instanceof WhatsAppDeliveryAmbiguousException);
            $terminal = $attempts > count($retrySeconds);
            $attributes = [
                'status' => $ambiguous ? 'ambiguous' : ($terminal ? 'failed' : 'pending'),
                'next_attempt_at' => ($terminal || $ambiguous) ? null : now()->addSeconds($retrySeconds[min($attempts - 1, count($retrySeconds) - 1)]),
                'last_error' => ($delivery->channel === 'whatsapp' ? 'WhatsApp delivery failed (' : 'Email delivery failed (').$exception::class.').',
            ];
            if ($delivery->channel === 'whatsapp') {
                $attributes['provider_attempts'] = $this->providerAttemptsWithStatus(
                    $delivery,
                    $ambiguous ? 'ambiguous' : 'failed',
                    ['error' => $exception::class],
                );
            }
            $delivery->update($attributes);

            Log::warning('Transactional notification delivery failed.', [
                'notification_delivery_id' => $delivery->id,
                'attempt' => $attempts,
                'terminal' => $terminal,
                'ambiguous' => $ambiguous,
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * @param  array<string, scalar|null>  $details
     * @return list<array<string, scalar|null>>
     */
    private function providerAttemptsWithStatus(NotificationDelivery $delivery, string $status, array $details = []): array
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
