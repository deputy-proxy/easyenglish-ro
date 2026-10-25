<?php

namespace App\Contracts\Payments;

interface StripeGateway
{
    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function createCheckoutSession(array $parameters, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function retrieveCheckoutSession(string $sessionId): array;

    /** @return array<string, mixed> */
    public function expireCheckoutSession(string $sessionId): array;

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function createRefund(array $parameters, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function retrieveRefund(string $refundId): array;
}
