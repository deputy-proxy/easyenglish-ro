<?php

namespace App\Data\CallEvents;

use Carbon\CarbonImmutable;

final readonly class CallEventResult
{
    public function __construct(
        public string $status,
        public ?string $providerEventId = null,
        public ?string $joinUrl = null,
        public ?CarbonImmutable $createdAt = null,
    ) {}
}
