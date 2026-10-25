<?php

namespace App\Console\Commands;

use App\Jobs\SendNotificationDelivery;
use App\Models\NotificationDelivery;
use App\Services\Notifications\NotificationOutboxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DispatchNotificationOutbox extends Command
{
    protected $signature = 'notifications:dispatch {--limit=100 : Maximum notification deliveries to queue}';

    protected $description = 'Queue due transactional emails and generate scheduled appointment/bundle reminders';

    public function handle(NotificationOutboxService $outbox): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $reminders = $outbox->queueDueReminders();

        // Recover leases left behind by a killed worker. Email and WhatsApp providers cannot guarantee exactly-once
        // delivery after a connection drops mid-send, but the unique outbox record prevents duplicate logical events.
        NotificationDelivery::query()->where('status', 'sending')
            ->where('updated_at', '<=', now()->subMinutes(20))
            ->update([
                'status' => 'pending',
                'next_attempt_at' => now(),
                'last_error' => 'Delivery worker lease expired; record returned to the retry queue.',
            ]);

        $queued = 0;
        $failures = 0;
        NotificationDelivery::query()->where(function ($query): void {
            $query->where('channel', 'email');
            if ((bool) config('services.whatsapp.enabled')) {
                $query->orWhere('channel', 'whatsapp');
            }
        })->where('status', 'pending')
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('id')->limit($limit)->get()->each(function (NotificationDelivery $candidate) use (&$queued, &$failures): void {
                $claimed = DB::transaction(function () use ($candidate): bool {
                    $delivery = NotificationDelivery::query()->lockForUpdate()->find($candidate->id);
                    if (! $delivery || ! in_array($delivery->channel, ['email', 'whatsapp'], true) || $delivery->status !== 'pending'
                        || ($delivery->next_attempt_at !== null && now()->parse($delivery->next_attempt_at)->isFuture())) {
                        return false;
                    }
                    $delivery->update(['status' => 'queued', 'last_error' => null]);

                    return true;
                }, attempts: 3);
                if (! $claimed) {
                    return;
                }

                try {
                    SendNotificationDelivery::dispatch($candidate->id);
                    $queued++;
                } catch (\Throwable $exception) {
                    $failures++;
                    NotificationDelivery::query()->whereKey($candidate->id)->where('status', 'queued')->update([
                        'status' => 'pending',
                        'next_attempt_at' => now()->addMinute(),
                        'last_error' => 'Queue dispatch failed ('.$exception::class.').',
                    ]);
                    Log::warning('Transactional email could not be queued.', [
                        'notification_delivery_id' => $candidate->id,
                        'exception' => $exception::class,
                    ]);
                }
            });

        $this->info("Generated {$reminders} reminder(s); queued {$queued} notification(s); {$failures} queue dispatch failure(s).");

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
