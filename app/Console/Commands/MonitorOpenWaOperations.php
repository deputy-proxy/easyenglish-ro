<?php

namespace App\Console\Commands;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\CallEvents\OpenWaHttpClient;
use App\Services\Notifications\WhatsAppProviderConfiguration;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class MonitorOpenWaOperations extends Command
{
    protected $signature = 'openwa:monitor';

    protected $description = 'Monitor OpenWA readiness, call-event backlog, notification failures, and queue pressure';

    public function handle(OpenWaHttpClient $client): int
    {
        $alerts = [];
        $recovered = [];
        $openWaReady = null;
        $providerErrorsInWindow = null;
        $staleCallEvents = 0;
        $providerConfiguration = app(WhatsAppProviderConfiguration::class);
        $metaVerificationEnabled = $providerConfiguration->isMetaVerificationEnabled();
        $metaVerificationReady = $providerConfiguration->isMetaVerificationConfigured();
        $metaTransactionalEnabled = (bool) config('services.whatsapp.transactional_enabled');
        $metaTransactionalReady = $providerConfiguration->isMetaTransactionalConfigured();
        $this->trackCondition(
            'meta-call-link-readiness',
            $metaTransactionalEnabled && ! $metaTransactionalReady,
            'Meta call-link delivery is not ready',
            'Meta call-link notifications are enabled but the Cloud API credentials or approved customer/teacher call-link templates are incomplete. Check php artisan whatsapp:provider-status.',
            $alerts,
            $recovered,
        );

        $this->trackCondition(
            'meta-verification-readiness',
            $metaVerificationEnabled && ! $metaVerificationReady,
            'Meta WhatsApp verification is not ready',
            'Meta contact verification is enabled but its Cloud API credentials, approved authentication template, or webhook callback configuration are incomplete. Check php artisan whatsapp:provider-status.',
            $alerts,
            $recovered,
        );

        $openWaMessagingEnabled = $providerConfiguration->isOpenWaMessagingEnabled();
        $openWaMessagingConfigured = $providerConfiguration->isOpenWaMessagingConfigured();
        $openWaMessagingReady = null;
        if ($openWaMessagingEnabled) {
            $this->trackCondition(
                'openwa-messaging-configuration',
                ! $openWaMessagingConfigured,
                'OpenWA messaging configuration is incomplete',
                'OpenWA notifications are enabled but the HTTPS service URL, service token, or callback secret is missing or invalid. Check php artisan whatsapp:provider-status.',
                $alerts,
                $recovered,
            );
        }

        if ((bool) config('services.openwa.enabled')) {
            $ready = $client->isReady();
            $openWaReady = $ready;
            $this->trackCondition(
                'openwa-readiness',
                ! $ready,
                'OpenWA service is not ready',
                'OpenWA is enabled but the service readiness check failed. New call-event operations will retry; check Railway deployment health, session state, and service authentication.',
                $alerts,
                $recovered,
            );

            $staleCallEvents = DB::table('booking_call_events')
                ->whereIn('status', ['pending', 'queued', 'reconciliation_pending', 'cancel_pending'])
                ->where('updated_at', '<=', now()->subMinutes((int) config('services.openwa.monitor.stale_event_minutes', 20)))
                ->where(function ($query): void {
                    $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                })->count();
            $metrics = $client->metrics();
            if ($metrics !== null) {
                $previousErrors = Cache::get('openwa-metrics:provider-errors-last');
                $currentErrors = $metrics['providerErrors'];
                $errorDelta = is_int($previousErrors) && $currentErrors >= $previousErrors
                    ? $currentErrors - $previousErrors
                    : 0;
                Cache::put('openwa-metrics:provider-errors-last', $currentErrors, now()->addDay());
                if ($errorDelta > 0) {
                    $bucket = 'openwa-metrics:provider-errors:'.now()->format('YmdHi');
                    Cache::put($bucket, (int) Cache::get($bucket, 0) + $errorDelta, now()->addMinutes(15));
                }
                $windowMinutes = (int) config('services.openwa.monitor.provider_error_window_minutes', 10);
                $windowErrors = 0;
                for ($minute = 0; $minute < $windowMinutes; $minute++) {
                    $bucket = 'openwa-metrics:provider-errors:'.now()->subMinutes($minute)->format('YmdHi');
                    $windowErrors += (int) Cache::get($bucket, 0);
                }
                $providerErrorsInWindow = $windowErrors;
                $errorThreshold = (int) config('services.openwa.monitor.provider_error_threshold', 5);
                $this->trackCondition(
                    'openwa-provider-error-rate',
                    $windowErrors >= $errorThreshold,
                    'OpenWA provider error rate is elevated',
                    "The OpenWA provider reported {$windowErrors} errors in the last {$windowMinutes} minute(s). Check Railway service logs and session connectivity before retrying affected events.",
                    $alerts,
                    $recovered,
                );
            }

            $this->trackCondition(
                'openwa-call-event-backlog',
                $staleCallEvents > 0,
                'OpenWA call-event operations are overdue',
                "{$staleCallEvents} call-event operation(s) have remained due for longer than the configured threshold. Review Admin → Scheduling → Call events and the queue worker.",
                $alerts,
                $recovered,
            );
        }

        if ($openWaMessagingEnabled) {
            $baseUrl = rtrim((string) config('services.whatsapp.openwa.base_url'), '/');
            $token = config('services.whatsapp.openwa.service_token');
            $ready = false;
            if ($baseUrl !== '' && is_string($token) && $token !== '') {
                try {
                    $response = Http::acceptJson()->withToken($token)->connectTimeout(5)->timeout(8)->get($baseUrl.'/v1/capabilities');
                    $ready = $response->successful() && $response->json('messagingReady') === true;
                } catch (\Throwable) {
                    $ready = false;
                }
            }
            $openWaMessagingReady = $ready;
            $this->trackCondition(
                'openwa-messaging-readiness',
                ! $ready,
                'OpenWA notification messaging is not ready',
                'OpenWA notifications are enabled but the messaging capability is not ready. Check the Railway service, paired session, and messaging callback configuration. Meta is reserved for verification and is not an automatic fallback.',
                $alerts,
                $recovered,
            );
        }

        $ambiguousDeliveries = NotificationDelivery::query()
            ->where('channel', 'whatsapp')
            ->where('status', 'ambiguous')
            ->count();
        $this->trackCondition(
            'whatsapp-ambiguous-deliveries',
            $ambiguousDeliveries > 0,
            'WhatsApp deliveries require reconciliation',
            "{$ambiguousDeliveries} WhatsApp delivery(ies) have an unknown provider submission outcome. Reconcile with the recorded provider before retrying.",
            $alerts,
            $recovered,
        );

        $failedDeliveries = NotificationDelivery::query()
            ->where('status', 'failed')
            ->where('updated_at', '>=', now()->subMinutes((int) config('services.openwa.monitor.delivery_failure_window_minutes', 30)))
            ->count();
        $this->trackCondition(
            'openwa-notification-deliveries',
            $failedDeliveries > 0,
            'Transactional notification deliveries have failed',
            "{$failedDeliveries} notification delivery(ies) entered a terminal failed state in the monitoring window. Review Operations → Notification delivery and retry only after correcting the cause.",
            $alerts,
            $recovered,
        );

        $queueCount = 0;
        $oldestJobAgeMinutes = 0.0;
        if (Schema::hasTable('jobs')) {
            $queueCount = DB::table('jobs')->count();
            $oldestCreatedAt = DB::table('jobs')->oldest('created_at')->value('created_at');
            if ($oldestCreatedAt !== null) {
                $nowTimestamp = (int) now()->timestamp;
                $oldestJobCreatedAt = (int) $oldestCreatedAt;
                $oldestJobAgeMinutes = (float) max(0, $nowTimestamp - $oldestJobCreatedAt) / 60;
            }
        }
        $queueThreshold = (int) config('services.openwa.monitor.queue_backlog_threshold', 50);
        $queueAgeThreshold = (int) config('services.openwa.monitor.queue_oldest_age_minutes', 10);
        $queueBacklogged = $queueCount >= $queueThreshold || $oldestJobAgeMinutes >= $queueAgeThreshold;
        $this->trackCondition(
            'openwa-queue-backlog',
            $queueBacklogged,
            'Laravel queue backlog is above the operational threshold',
            sprintf('The database queue contains %d pending job(s); the oldest job is %.1f minute(s) old. Check Hostinger cron cadence, worker execution limits, and failed jobs.', $queueCount, $oldestJobAgeMinutes),
            $alerts,
            $recovered,
        );

        Log::info('OpenWA operations monitor completed.', [
            'openwa_enabled' => (bool) config('services.openwa.enabled'),
            'meta_verification_enabled' => $metaVerificationEnabled,
            'meta_verification_ready' => $metaVerificationReady,
            'openwa_messaging_enabled' => $openWaMessagingEnabled,
            'openwa_messaging_configured' => $openWaMessagingConfigured,
            'openwa_messaging_ready' => $openWaMessagingReady,
            'openwa_ready' => $openWaReady,
            'provider_errors_in_window' => $providerErrorsInWindow,
            'stale_call_event_operations' => $staleCallEvents,
            'alerts_created' => count($alerts),
            'conditions_recovered' => count($recovered),
            'failed_notification_deliveries' => $failedDeliveries,
            'ambiguous_whatsapp_deliveries' => $ambiguousDeliveries,
            'queue_backlog' => $queueCount,
            'oldest_queue_job_age_minutes' => round($oldestJobAgeMinutes, 1),
        ]);

        $this->info(sprintf(
            'OpenWA monitor complete: %d new alert(s), %d recovered condition(s), %d failed delivery(ies), %d queued job(s).',
            count($alerts), count($recovered), $failedDeliveries, $queueCount,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $alerts
     * @param  list<string>  $recovered
     */
    private function trackCondition(string $key, bool $active, string $title, string $body, array &$alerts, array &$recovered): void
    {
        $cacheKey = 'operations-alert:'.$key;
        if ($active) {
            if (! Cache::add($cacheKey, true, now()->addHours(24))) {
                return;
            }
            $this->notifyStaff($title, $body, danger: true);
            $alerts[] = $key;

            return;
        }

        if (Cache::pull($cacheKey) !== null) {
            $this->notifyStaff($title.' recovered', 'The monitored condition has returned below its alert threshold.', danger: false);
            $recovered[] = $key;
        }
    }

    private function notifyStaff(string $title, string $body, bool $danger): void
    {
        $staff = User::query()->where('is_staff', true)->get();
        if ($staff->isEmpty()) {
            Log::warning('Operations alert could not be delivered because no staff accounts exist.', ['alert_title' => $title]);

            return;
        }

        foreach ($staff as $user) {
            $notification = FilamentNotification::make()->title($title)->body($body);
            ($danger ? $notification->danger() : $notification->success())->sendToDatabase($user);
        }
    }
}
