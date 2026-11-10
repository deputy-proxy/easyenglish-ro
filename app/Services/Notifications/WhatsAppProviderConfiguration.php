<?php

namespace App\Services\Notifications;

class WhatsAppProviderConfiguration
{
    public function isMetaVerificationEnabled(): bool
    {
        return (bool) config('services.whatsapp.verification_enabled');
    }

    public function isMetaVerificationConfigured(): bool
    {
        return $this->isMetaVerificationEnabled()
            && filled(config('services.whatsapp.access_token'))
            && filled(config('services.whatsapp.phone_number_id'))
            && filled(config('services.whatsapp.app_secret'))
            && filled(config('services.whatsapp.verify_token'))
            && is_string(config('services.whatsapp.api_version'))
            && preg_match('/^v[0-9]+\.[0-9]+$/', (string) config('services.whatsapp.api_version')) === 1
            && is_string(config('services.whatsapp.templates.contact_verification'))
            && preg_match('/^[a-z0-9_]{1,128}$/', (string) config('services.whatsapp.templates.contact_verification')) === 1;
    }

    public function isOpenWaMessagingEnabled(): bool
    {
        return (bool) config('services.whatsapp.openwa.enabled');
    }

    public function isOpenWaMessagingOutboundConfigured(): bool
    {
        if (! $this->isOpenWaMessagingEnabled()) {
            return false;
        }

        $baseUrl = config('services.whatsapp.openwa.base_url');
        $token = config('services.whatsapp.openwa.service_token');
        $parts = is_string($baseUrl) ? parse_url($baseUrl) : false;

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && isset($parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && is_string($token)
            && strlen($token) >= 32;
    }

    public function isOpenWaMessagingConfigured(): bool
    {
        return $this->isOpenWaMessagingOutboundConfigured()
            && is_string(config('services.whatsapp.openwa.callback_secret'))
            && strlen((string) config('services.whatsapp.openwa.callback_secret')) >= 32;
    }

    public function isOpenWaCallEventsEnabled(): bool
    {
        return (bool) config('services.openwa.enabled');
    }
}
