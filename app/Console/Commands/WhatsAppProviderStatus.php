<?php

namespace App\Console\Commands;

use App\Services\Notifications\WhatsAppProviderConfiguration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class WhatsAppProviderStatus extends Command
{
    protected $signature = 'whatsapp:provider-status';

    protected $description = 'Show the selected WhatsApp provider and secret-free configuration readiness';

    public function handle(): int
    {
        $configuration = app(WhatsAppProviderConfiguration::class);
        $provider = $configuration->provider();
        $this->line('Active outbound provider: '.$provider);
        $this->line('Meta configured: '.($this->metaConfigured() ? 'yes' : 'no'));
        $this->line('OpenWA messaging configured: '.($this->openWaConfigured() ? 'yes' : 'no'));

        if (! in_array($provider, ['meta', 'openwa'], true)) {
            $this->error('WHATSAPP_PROVIDER must be either meta or openwa.');

            return self::FAILURE;
        }
        $ready = $configuration->isConfigured();
        if ($provider === 'openwa' && $ready) {
            $baseUrl = rtrim((string) config('services.whatsapp.openwa.base_url'), '/');
            $token = (string) config('services.whatsapp.openwa.service_token');
            try {
                $response = Http::acceptJson()->withToken($token)->connectTimeout(5)->timeout(8)->get($baseUrl.'/v1/capabilities');
                $remoteReady = $response->successful() && $response->json('messagingReady') === true;
            } catch (\Throwable) {
                $remoteReady = false;
            }
            $this->line('OpenWA messaging session: '.($remoteReady ? 'ready' : 'not ready'));
            $ready = $remoteReady;
        }
        $this->line('Selected provider configuration: '.($ready ? 'ready' : 'incomplete or unavailable'));

        return $ready ? self::SUCCESS : self::FAILURE;
    }

    private function metaConfigured(): bool
    {
        return (bool) config('services.whatsapp.enabled')
            && filled(config('services.whatsapp.access_token'))
            && filled(config('services.whatsapp.phone_number_id'))
            && filled(config('services.whatsapp.app_secret'))
            && filled(config('services.whatsapp.verify_token'))
            && preg_match('/^v[0-9]+\\.[0-9]+$/', (string) config('services.whatsapp.api_version')) === 1;
    }

    private function openWaConfigured(): bool
    {
        return (bool) config('services.whatsapp.openwa.enabled')
            && filled(config('services.whatsapp.openwa.base_url'))
            && filled(config('services.whatsapp.openwa.service_token'))
            && is_string(config('services.whatsapp.openwa.callback_secret'))
            && strlen((string) config('services.whatsapp.openwa.callback_secret')) >= 32
            && str_starts_with((string) config('services.whatsapp.openwa.base_url'), 'https://');
    }
}
