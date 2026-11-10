<?php

namespace App\Console\Commands;

use App\Services\CallEvents\OpenWaHttpClient;
use App\Services\Notifications\WhatsAppProviderConfiguration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class WhatsAppProviderStatus extends Command
{
    protected $signature = 'whatsapp:provider-status';

    protected $description = 'Show purpose-specific WhatsApp configuration and live readiness';

    public function handle(OpenWaHttpClient $callEvents): int
    {
        $configuration = app(WhatsAppProviderConfiguration::class);
        $failed = false;

        $metaEnabled = $configuration->isMetaVerificationEnabled();
        $metaReady = $configuration->isMetaVerificationConfigured();
        $this->line('Meta verification: '.($metaEnabled ? ($metaReady ? 'configured' : 'incomplete') : 'disabled'));
        $this->line('Meta webhook/status callbacks: '.($metaEnabled ? ($metaReady ? 'configured' : 'incomplete') : 'inactive'));
        $failed = $metaEnabled && ! $metaReady;

        $openWaEnabled = $configuration->isOpenWaMessagingEnabled();
        $openWaConfigured = $configuration->isOpenWaMessagingConfigured();
        $this->line('OpenWA notifications: '.($openWaEnabled ? ($openWaConfigured ? 'configured' : 'incomplete') : 'disabled'));
        if ($openWaEnabled) {
            $remoteReady = false;
            if ($configuration->isOpenWaMessagingOutboundConfigured()) {
                $baseUrl = rtrim((string) config('services.whatsapp.openwa.base_url'), '/');
                $token = (string) config('services.whatsapp.openwa.service_token');
                try {
                    $response = Http::acceptJson()->withToken($token)->connectTimeout(5)->timeout(8)->get($baseUrl.'/v1/capabilities');
                    $remoteReady = $response->successful() && $response->json('messagingReady') === true;
                } catch (\Throwable) {
                    $remoteReady = false;
                }
            }
            $this->line('OpenWA messaging session: '.($remoteReady ? 'ready' : 'not ready'));
            $failed = $failed || ! $openWaConfigured || ! $remoteReady;
        }

        $callEventsEnabled = $configuration->isOpenWaCallEventsEnabled();
        $this->line('OpenWA scheduled call events: '.($callEventsEnabled ? 'enabled' : 'disabled'));
        if ($callEventsEnabled) {
            $callEventsReady = $callEvents->isReady();
            $this->line('OpenWA call-event service: '.($callEventsReady ? 'ready' : 'not ready'));
            $failed = $failed || ! $callEventsReady;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
