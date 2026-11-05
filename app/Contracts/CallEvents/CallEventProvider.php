<?php

namespace App\Contracts\CallEvents;

use App\Data\CallEvents\CallEventResult;

interface CallEventProvider
{
    public function name(): string;

    public function isReady(): bool;

    /** @param array<string, string> $payload */
    public function create(array $payload, string $idempotencyKey): CallEventResult;

    public function retrieve(string $providerEventId): ?CallEventResult;

    public function cancel(string $providerEventId): bool;
}
