<?php

namespace App\Jobs;

use App\Contracts\Notifications\WhatsAppGateway;
use App\Exceptions\NotificationNoLongerEligibleException;
use App\Mail\TransactionalNotificationEmail;
use App\Models\NotificationDelivery;
use App\Models\WhatsAppContact;
use App\Services\Notifications\NotificationMessageFactory;
use App\Services\Notifications\WhatsAppMessageFactory;
use App\Support\PhoneNumber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
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

    public function handle(NotificationMessageFactory $messages, WhatsAppMessageFactory $whatsappMessages, WhatsAppGateway $whatsapp): void
    {
        $claimed = DB::transaction(function (): bool {
            $delivery = NotificationDelivery::query()->lockForUpdate()->find($this->deliveryId);
            if (! $delivery || ! in_array($delivery->channel, ['email', 'whatsapp'], true) || $delivery->status !== 'queued') {
                return false;
            }

            $delivery->update(['status' => 'sending', 'attempts' => $delivery->attempts + 1, 'last_error' => null]);

            return true;
        }, attempts: 3);

        if (! $claimed) {
            return;
        }

        try {
            $delivery = NotificationDelivery::query()->with(['booking', 'entitlement', 'refund.payment.entitlement'])->findOrFail($this->deliveryId);
            if ($delivery->channel === 'whatsapp') {
                if (! (bool) config('services.whatsapp.enabled')) {
                    throw new NotificationNoLongerEligibleException('WhatsApp sending is disabled.');
                }
                $phone = $delivery->booking->customer_phone ?? $delivery->entitlement->customer_phone ?? $delivery->refund->payment->entitlement->customer_phone;
                $contact = WhatsAppContact::query()->where('phone', PhoneNumber::normalize((string) $phone))->first();
                if ($contact === null || ! $contact->canReceiveServiceMessages()) {
                    throw new NotificationNoLongerEligibleException('Customer has not opted in to WhatsApp service messages or has opted out.');
                }
                $messageData = $whatsappMessages->make($delivery);
                $result = $whatsapp->sendTemplate(
                    $messageData['recipient'],
                    $messageData['template'],
                    $messageData['language'],
                    $messageData['components'],
                    'delivery-'.$delivery->id,
                );
                NotificationDelivery::query()->whereKey($this->deliveryId)->where('status', 'sending')->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'next_attempt_at' => null,
                    'last_error' => null,
                    'provider_message_id' => $result['message_id'],
                    'provider_status' => $result['status'],
                    'provider_error_code' => null,
                ]);
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
            NotificationDelivery::query()->whereKey($this->deliveryId)->where('status', 'sending')->update([
                'status' => 'skipped',
                'next_attempt_at' => null,
                'last_error' => $exception->getMessage(),
            ]);

            return;
        } catch (Throwable $exception) {
            $delivery = NotificationDelivery::query()->find($this->deliveryId);
            if (! $delivery || $delivery->status !== 'sending') {
                return;
            }

            $attempts = (int) $delivery->attempts;
            $retrySeconds = [60, 300, 900, 3600, 14400];
            $terminal = $attempts > count($retrySeconds);
            $delivery->update([
                'status' => $terminal ? 'failed' : 'pending',
                'next_attempt_at' => $terminal ? null : now()->addSeconds($retrySeconds[min($attempts - 1, count($retrySeconds) - 1)]),
                'last_error' => ($delivery->channel === 'whatsapp' ? 'WhatsApp delivery failed (' : 'Email delivery failed (').$exception::class.').',
            ]);

            Log::warning('Transactional notification delivery failed.', [
                'notification_delivery_id' => $delivery->id,
                'attempt' => $attempts,
                'terminal' => $terminal,
                'exception' => $exception::class,
            ]);
        }
    }
}
