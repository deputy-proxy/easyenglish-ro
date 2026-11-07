<?php

namespace App\Services\Notifications;

use InvalidArgumentException;

class WhatsAppProviderConfiguration
{
    public function provider(): string
    {
        $provider = strtolower(trim((string) config('services.whatsapp.provider', 'meta')));

        if (! in_array($provider, ['meta', 'openwa'], true)) {
            throw new InvalidArgumentException('WHATSAPP_PROVIDER must be either meta or openwa.');
        }

        return $provider;
    }

    public function isEnabled(): bool
    {
        return match ($this->provider()) {
            'meta' => (bool) config('services.whatsapp.enabled'),
            'openwa' => (bool) config('services.whatsapp.openwa.enabled'),
            default => throw new InvalidArgumentException('WHATSAPP_PROVIDER must be either meta or openwa.'),
        };
    }

    public function isOutboundConfigured(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        return match ($this->provider()) {
            'meta' => filled(config('services.whatsapp.access_token'))
                && filled(config('services.whatsapp.phone_number_id'))
                && is_string(config('services.whatsapp.api_version'))
                && preg_match('/^v[0-9]+\\.[0-9]+$/', (string) config('services.whatsapp.api_version')) === 1,
            'openwa' => $this->openWaOutboundConfigured(),
            default => throw new InvalidArgumentException('WHATSAPP_PROVIDER must be either meta or openwa.'),
        };
    }

    public function isConfigured(): bool
    {
        if (! $this->isOutboundConfigured()) {
            return false;
        }

        return match ($this->provider()) {
            'meta' => filled(config('services.whatsapp.app_secret')) && filled(config('services.whatsapp.verify_token')),
            'openwa' => is_string(config('services.whatsapp.openwa.callback_secret'))
                && strlen((string) config('services.whatsapp.openwa.callback_secret')) >= 32,
            default => throw new InvalidArgumentException('WHATSAPP_PROVIDER must be either meta or openwa.'),
        };
    }

    private function openWaOutboundConfigured(): bool
    {
        $baseUrl = config('services.whatsapp.openwa.base_url');
        $token = config('services.whatsapp.openwa.service_token');
        $parts = is_string($baseUrl) ? parse_url($baseUrl) : false;

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && isset($parts['host'])
            && ! isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            && is_string($token) && strlen($token) >= 32;
    }
}
