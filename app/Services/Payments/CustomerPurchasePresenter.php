<?php

namespace App\Services\Payments;

use App\Models\BundleEntitlement;
use App\Models\Payment;

class CustomerPurchasePresenter
{
    public function entitlementStatus(BundleEntitlement $entitlement): string
    {
        if ($entitlement->status === 'refunded') {
            return 'refunded';
        }

        if ($entitlement->status === 'active') {
            if ($entitlement->expires_at !== null && $entitlement->expires_at->isPast()) {
                return 'expired';
            }

            if ($entitlement->credits_remaining + $entitlement->free_credits_remaining < 1) {
                return 'consumed';
            }

            return 'active';
        }

        if ($entitlement->status === 'pending_payment') {
            if ($entitlement->payments->contains(fn ($payment): bool => $payment->status === 'succeeded')) {
                return 'activation_pending';
            }

            $latestPayment = $entitlement->payments->sortByDesc('id')->first();

            return match ($latestPayment?->status) {
                'failed' => 'payment_failed',
                'expired' => 'checkout_expired',
                'cancelled' => 'checkout_cancelled',
                default => 'pending_payment',
            };
        }

        return $entitlement->status;
    }

    public function paymentStatus(Payment $payment): string
    {
        $latestDispute = $payment->disputes->sortByDesc(
            fn ($dispute): int => $dispute->created_at_provider?->getTimestamp() ?? $dispute->id,
        )->first();

        if ($latestDispute !== null) {
            return match ($latestDispute->status) {
                'needs_response', 'under_review' => 'chargeback_open',
                'warning_needs_response', 'warning_under_review' => 'dispute_warning',
                'warning_closed' => 'dispute_warning_closed',
                'won' => 'chargeback_won',
                'lost' => 'chargeback_lost',
                'charge_refunded' => 'chargeback_refunded',
                'prevented' => 'chargeback_prevented',
                default => 'chargeback_status_unknown',
            };
        }

        if ($payment->refunds->contains(fn ($refund): bool => in_array($refund->status, ['pending', 'processing'], true))) {
            return 'refund_pending';
        }

        if ($payment->status === 'refunded') {
            return 'refunded';
        }

        if ($payment->refunded_amount_ron > 0) {
            return 'partially_refunded';
        }

        return $payment->status;
    }
}
