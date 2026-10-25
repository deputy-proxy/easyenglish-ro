<?php

namespace App\Services\Payments;

use App\Contracts\Payments\StripeGateway;
use Stripe\StripeClient;

class StripeSdkGateway implements StripeGateway
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function createCheckoutSession(array $parameters, string $idempotencyKey): array
    {
        return $this->stripe->checkout->sessions->create($parameters, ['idempotency_key' => $idempotencyKey])->toArray();
    }

    public function retrieveCheckoutSession(string $sessionId): array
    {
        return $this->stripe->checkout->sessions->retrieve($sessionId)->toArray();
    }

    public function expireCheckoutSession(string $sessionId): array
    {
        return $this->stripe->checkout->sessions->expire($sessionId)->toArray();
    }

    public function createRefund(array $parameters, string $idempotencyKey): array
    {
        return $this->stripe->refunds->create($parameters, ['idempotency_key' => $idempotencyKey])->toArray();
    }

    public function retrieveRefund(string $refundId): array
    {
        return $this->stripe->refunds->retrieve($refundId)->toArray();
    }
}
