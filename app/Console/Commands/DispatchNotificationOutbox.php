<?php

namespace App\Console\Commands;

use App\Jobs\SendNotificationDelivery;
use App\Models\NotificationDelivery;
use App\Services\Notifications\NotificationOutboxService;
use App\Services\Notifications\WhatsAppProviderConfiguration;
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

        // Recover leases left behind by a killed worker. A WhatsApp request may have reached the provider before the worker
        // stopped, so its outcome is quarantined as ambiguous instead of being replayed automatically.
        NotificationDelivery::query()->where('status', 'sending')
            ->where('updated_at', '<=', now()->subMinutes(20))
            ->where('channel', 'email')
            ->update([
                'status' => 'pending',
                'next_attempt_at' => now(),
                'last_error' => 'Delivery worker lease expired; record returned to the retry queue.',
            ]);
        NotificationDelivery::query()->where('status', 'sending')->where('channel', 'whatsapp')
            ->where('updated_at', '<=', now()->subMinutes(20))
            ->get()
            ->each(function (NotificationDelivery $delivery): void {
                $attempts = $delivery->provider_attempts ?? [];
                if ($attempts !== []) {
                    $last = array_key_last($attempts);
                    $attempts[$last] = array_merge($attempts[$last], [
                        'status' => 'ambiguous',
                        'completed_at' => now()->toIso8601String(),
                        'reason' => 'worker_lease_expired',
                    ]);
                }
                $delivery->update([
                    'status' => 'ambiguous',
                    'next_attempt_at' => null,
                    'last_error' => 'WhatsApp send outcome is unknown; reconcile with the provider before retrying.',
                    'provider_attempts' => $attempts,
                ]);
            });

        $queued = 0;
        $failures = 0;
        NotificationDelivery::query()->where(function ($query): void {
            $query->where('channel', 'email');
            if (app(WhatsAppProviderConfiguration::class)->isOpenWaMessagingConfigured()) {
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
