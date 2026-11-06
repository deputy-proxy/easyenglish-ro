<?php

namespace App\Services\Payments;

use App\Models\BookingAudit;
use App\Models\Payment;
use App\Models\PaymentDispute;
use Carbon\CarbonImmutable;
use RuntimeException;

class StripeDisputeService
{
    /** @param array<string, mixed> $dispute */
    public function reconcile(array $dispute, string $eventId): PaymentDispute
    {
        $disputeId = $dispute['id'] ?? null;
        $paymentIntentId = $this->providerId($dispute['payment_intent'] ?? null);
        $chargeId = $this->providerId($dispute['charge'] ?? null);

        if (! is_string($disputeId) || $disputeId === '') {
            throw new RuntimeException('Stripe dispute is missing its provider dispute ID.');
        }

        if ($paymentIntentId === null && $chargeId === null) {
            throw new RuntimeException('Stripe dispute is missing a payment-intent and charge reference.');
        }

        $payment = $paymentIntentId !== null
            ? Payment::query()->where('provider_payment_id', $paymentIntentId)->lockForUpdate()->first()
            : null;

        if (! $payment && $chargeId !== null) {
            $payment = Payment::query()->where('provider_charge_id', $chargeId)->lockForUpdate()->first();
        }

        if (! $payment) {
            throw new RuntimeException('Stripe dispute could not be matched to a local payment.');
        }

        if ($chargeId !== null && $payment->provider_charge_id !== null && $payment->provider_charge_id !== $chargeId) {
            throw new RuntimeException('Stripe dispute charge reference does not match its PaymentIntent payment.');
        }

        $currency = strtoupper((string) ($dispute['currency'] ?? ''));
        $amountMinor = filter_var($dispute['amount'] ?? null, FILTER_VALIDATE_INT);
        if ($currency !== $payment->currency || ! is_int($amountMinor) || $amountMinor < 0 || $amountMinor > $payment->amount_ron * 100) {
            throw new RuntimeException('Stripe dispute amount or currency does not match its local payment.');
        }

        $status = $dispute['status'] ?? null;
        if (! is_string($status) || $status === '') {
            throw new RuntimeException('Stripe dispute is missing its current status.');
        }

        $evidenceDueBy = data_get($dispute, 'evidence_details.due_by');
        $createdAt = $dispute['created'] ?? null;
        $closedAt = in_array($status, ['won', 'lost', 'warning_closed', 'charge_refunded'], true) ? now() : null;

        $record = PaymentDispute::query()->updateOrCreate(
            ['provider_dispute_id' => $disputeId],
            [
                'payment_id' => $payment->id,
                'provider_payment_intent_id' => $paymentIntentId,
                'provider_charge_id' => $chargeId,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'status' => $status,
                'reason' => is_string($dispute['reason'] ?? null) ? $dispute['reason'] : null,
                'evidence_due_at' => is_numeric($evidenceDueBy) ? CarbonImmutable::createFromTimestampUTC((int) $evidenceDueBy) : null,
                'created_at_provider' => is_numeric($createdAt) ? CarbonImmutable::createFromTimestampUTC((int) $createdAt) : null,
                'closed_at_provider' => $closedAt,
                'last_event_id' => $eventId,
            ],
        );

        BookingAudit::query()->create([
            'bundle_entitlement_id' => $payment->bundle_entitlement_id,
            'action' => 'payment.dispute_reconciled',
            'metadata' => [
                'payment_id' => $payment->id,
                'dispute_id' => $record->id,
                'status' => $status,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'stripe_event_id' => $eventId,
            ],
        ]);

        return $record;
    }

    private function providerId(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_array($value) && is_string($value['id'] ?? null) && $value['id'] !== '') {
            return $value['id'];
        }

        return null;
    }
}
