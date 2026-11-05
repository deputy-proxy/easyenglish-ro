<?php

namespace App\Services\CallEvents;

use App\Contracts\CallEvents\CallEventProvider;
use App\Data\CallEvents\CallEventResult;

class OpenWaCallEventProvider implements CallEventProvider
{
    public function __construct(private readonly OpenWaHttpClient $client) {}

    public function name(): string
    {
        return 'openwa';
    }

    public function isReady(): bool
    {
        return $this->client->isReady();
    }

    public function create(array $payload, string $idempotencyKey): CallEventResult
    {
        return $this->client->createEvent($payload, $idempotencyKey);
    }

    public function retrieve(string $providerEventId): ?CallEventResult
    {
        return $this->client->retrieveEvent($providerEventId);
    }

    public function cancel(string $providerEventId): bool
    {
        return $this->client->cancelEvent($providerEventId);
    }
}
