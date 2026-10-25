<?php

namespace App\Jobs;

use App\Exceptions\NotificationNoLongerEligibleException;
use App\Mail\TransactionalNotificationEmail;
use App\Models\NotificationDelivery;
use App\Services\Notifications\NotificationMessageFactory;
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

    public function handle(NotificationMessageFactory $messages): void
    {
        $claimed = DB::transaction(function (): bool {
            $delivery = NotificationDelivery::query()->lockForUpdate()->find($this->deliveryId);
            if (! $delivery || $delivery->channel !== 'email' || $delivery->status !== 'queued') {
                return false;
            }

            $delivery->update(['status' => 'sending', 'attempts' => $delivery->attempts + 1, 'last_error' => null]);

            return true;
        }, attempts: 3);

        if (! $claimed) {
            return;
        }

        try {
            $delivery = NotificationDelivery::query()->with(['booking', 'entitlement', 'refund'])->findOrFail($this->deliveryId);
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
                'last_error' => 'Email delivery failed ('.$exception::class.').',
            ]);

            Log::warning('Transactional email delivery failed.', [
                'notification_delivery_id' => $delivery->id,
                'attempt' => $attempts,
                'terminal' => $terminal,
                'exception' => $exception::class,
            ]);
        }
    }
}
