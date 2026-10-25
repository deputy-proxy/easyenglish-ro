<?php

namespace Tests\Support;

use App\Contracts\Payments\StripeGateway;
use RuntimeException;

class FakeStripeGateway implements StripeGateway
{
    /** @var array<string, array<string, mixed>> */
    public array $sessions = [];

    /** @var list<array{parameters:array<string,mixed>,idempotency_key:string}> */
    public array $checkoutRequests = [];

    /** @var list<array{parameters:array<string,mixed>,idempotency_key:string}> */
    public array $refundRequests = [];

    /** @var array<string, array<string, mixed>> */
    public array $refunds = [];

    /** @var array<string, string> */
    private array $sessionKeys = [];

    /** @var array<string, string> */
    private array $refundKeys = [];

    public string $nextRefundStatus = 'succeeded';

    public function createCheckoutSession(array $parameters, string $idempotencyKey): array
    {
        $this->checkoutRequests[] = ['parameters' => $parameters, 'idempotency_key' => $idempotencyKey];
        if (isset($this->sessionKeys[$idempotencyKey])) {
            return $this->sessions[$this->sessionKeys[$idempotencyKey]];
        }

        $id = 'cs_test_'.(count($this->sessions) + 1);
        $lineItem = $parameters['line_items'][0] ?? [];
        $price = $lineItem['price_data'] ?? [];
        $session = [
            'id' => $id,
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/c/pay/'.$id,
            'expires_at' => (int) ($parameters['expires_at'] ?? now()->addHours(24)->timestamp),
            'mode' => $parameters['mode'] ?? 'payment',
            'status' => 'open',
            'payment_status' => 'unpaid',
            'currency' => strtolower((string) ($price['currency'] ?? 'ron')),
            'amount_total' => (int) ($price['unit_amount'] ?? 0) * (int) ($lineItem['quantity'] ?? 1),
            'client_reference_id' => $parameters['client_reference_id'] ?? null,
            'customer_email' => $parameters['customer_email'] ?? null,
            'payment_intent' => null,
            'metadata' => $parameters['metadata'] ?? [],
        ];
        $this->sessions[$id] = $session;
        $this->sessionKeys[$idempotencyKey] = $id;

        return $session;
    }

    public function retrieveCheckoutSession(string $sessionId): array
    {
        return $this->sessions[$sessionId] ?? throw new RuntimeException('Unknown fake Checkout Session.');
    }

    public function expireCheckoutSession(string $sessionId): array
    {
        $session = $this->retrieveCheckoutSession($sessionId);
        if ($session['status'] === 'open') {
            $session['status'] = 'expired';
            $this->sessions[$sessionId] = $session;
        }

        return $this->sessions[$sessionId];
    }

    public function createRefund(array $parameters, string $idempotencyKey): array
    {
        $this->refundRequests[] = ['parameters' => $parameters, 'idempotency_key' => $idempotencyKey];
        if (isset($this->refundKeys[$idempotencyKey])) {
            return $this->refunds[$this->refundKeys[$idempotencyKey]];
        }

        $id = 're_test_'.(count($this->refunds) + 1);
        $refund = [
            'id' => $id,
            'object' => 'refund',
            'amount' => (int) ($parameters['amount'] ?? 0),
            'currency' => 'ron',
            'payment_intent' => $parameters['payment_intent'] ?? null,
            'status' => $this->nextRefundStatus,
            'metadata' => $parameters['metadata'] ?? [],
        ];
        $this->refunds[$id] = $refund;
        $this->refundKeys[$idempotencyKey] = $id;

        return $refund;
    }

    public function retrieveRefund(string $refundId): array
    {
        return $this->refunds[$refundId] ?? throw new RuntimeException('Unknown fake refund.');
    }

    public function setSessionStatus(string $sessionId, string $status, string $paymentStatus = 'unpaid', ?string $paymentIntent = null): void
    {
        $this->sessions[$sessionId]['status'] = $status;
        $this->sessions[$sessionId]['payment_status'] = $paymentStatus;
        $this->sessions[$sessionId]['payment_intent'] = $paymentIntent;
    }

    public function setRefundStatus(string $refundId, string $status): void
    {
        $this->refunds[$refundId]['status'] = $status;
    }
}
