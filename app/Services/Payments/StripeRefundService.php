<?php

namespace App\Services\Payments;

use App\Contracts\Payments\StripeGateway;
use App\Enums\NotificationEvent;
use App\Models\Booking;
use App\Models\BookingAudit;
use App\Models\BundleEntitlement;
use App\Models\CreditTransaction;
use App\Models\FreeSessionClaim;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Services\Notifications\NotificationOutboxService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class StripeRefundService
{
    public function __construct(private readonly StripeGateway $stripe, private readonly NotificationOutboxService $notifications) {}

    public function refundUnusedPaidCredits(BundleEntitlement $entitlement, int $actorId, string $reason): PaymentRefund
    {
        $actor = User::query()->findOrFail($actorId);
        if (! $actor->is_staff) {
            throw new AuthorizationException('Only authorized staff can initiate bundle refunds.');
        }

        if (! is_string(config('services.stripe.secret')) || config('services.stripe.secret') === '') {
            throw new RuntimeException('Stripe is not configured.');
        }
        if (mb_strlen(trim($reason)) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Provide a reason between 10 and 500 characters.']);
        }

        $refund = DB::transaction(function () use ($entitlement, $actorId, $reason): PaymentRefund {
            DB::table('booking_mutexes')->where('id', 1)->lockForUpdate()->first();
            $lockedEntitlement = BundleEntitlement::query()->lockForUpdate()->findOrFail($entitlement->id);
            if ($lockedEntitlement->status !== 'active') {
                throw ValidationException::withMessages(['refund' => 'Only active bundles can be refunded.']);
            }
            $payment = $lockedEntitlement->payments()->whereIn('status', ['succeeded'])->whereNotNull('provider_payment_id')->latest('id')->lockForUpdate()->first();
            if (! $payment) {
                throw ValidationException::withMessages(['refund' => 'No successfully paid Stripe payment is available to refund.']);
            }

            $existingPending = $payment->refunds()->whereIn('status', ['pending', 'processing'])->latest('id')->first();
            if ($existingPending) {
                return $existingPending;
            }
            if (Booking::query()->where('bundle_entitlement_id', $lockedEntitlement->id)->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['refund' => 'A pending booking hold must be resolved before refunding unused credits.']);
            }

            $futureBookings = Booking::query()->where('bundle_entitlement_id', $lockedEntitlement->id)
                ->where('status', 'confirmed')->where('starts_at', '>', now())
                ->orderBy('id')->lockForUpdate()->get();
            $paidBookingIds = $futureBookings->where('credit_type', 'paid')->pluck('id')->values()->all();
            $freeBookingIds = $futureBookings->where('credit_type', 'free')->pluck('id')->values()->all();
            $balancePaidCredits = $lockedEntitlement->credits_remaining;
            $credits = $balancePaidCredits + count($paidBookingIds);
            if ($credits < 1) {
                throw ValidationException::withMessages(['refund' => 'This bundle has no unused paid credits to refund.']);
            }
            $amountRon = $credits * 39;
            $pendingRefunds = (int) $payment->refunds()->whereIn('status', ['pending', 'processing'])->sum('amount_ron');
            if ($payment->refunded_amount_ron + $pendingRefunds + $amountRon > $payment->amount_ron) {
                throw ValidationException::withMessages(['refund' => 'The requested refund exceeds the remaining refundable payment amount. Reconcile existing refunds first.']);
            }
            $fullRefund = $payment->refunded_amount_ron + $pendingRefunds + $amountRon >= $payment->amount_ron;
            $balanceFreeCredits = $fullRefund ? $lockedEntitlement->free_credits_remaining : 0;
            $freeBookingIdsToCancel = $fullRefund ? $freeBookingIds : [];
            $freeCredits = $balanceFreeCredits + count($freeBookingIdsToCancel);
            $attempt = $payment->refunds()->count() + 1;
            $created = $payment->refunds()->create([
                'amount_ron' => $amountRon,
                'currency' => 'RON',
                'credits_to_revoke' => $credits,
                'free_credits_to_revoke' => $freeCredits,
                'status' => 'pending',
                'reason' => trim($reason),
                'actor_id' => $actorId,
                'idempotency_key' => 'unused-credits-refund:'.$payment->id.':'.$attempt,
                'metadata' => [
                    'balance_paid_credits_reserved' => $balancePaidCredits,
                    'balance_free_credits_reserved' => $balanceFreeCredits,
                    'paid_booking_ids' => $paidBookingIds,
                    'free_booking_ids' => $freeBookingIdsToCancel,
                    'full_refund' => $fullRefund,
                    'accepted_policy_version' => $futureBookings->first()?->accepted_policy_version,
                ],
            ]);

            $newPaidBalance = $lockedEntitlement->credits_remaining - $balancePaidCredits;
            $newFreeBalance = $lockedEntitlement->free_credits_remaining - $balanceFreeCredits;
            $lockedEntitlement->update(['credits_remaining' => $newPaidBalance, 'free_credits_remaining' => $newFreeBalance]);
            CreditTransaction::query()->create([
                'bundle_entitlement_id' => $lockedEntitlement->id,
                'created_by' => $actorId,
                'type' => 'refund_reserved',
                'credit_type' => 'paid',
                'quantity' => -$balancePaidCredits,
                'balance_after' => $newPaidBalance,
                'reason' => 'Available paid credits reserved while Stripe refund is pending: '.trim($reason),
                'idempotency_key' => 'refund-reserved-paid-credits:'.$created->id,
            ]);
            if ($freeCredits > 0) {
                CreditTransaction::query()->create([
                    'bundle_entitlement_id' => $lockedEntitlement->id,
                    'created_by' => $actorId,
                    'type' => 'refund_reserved',
                    'credit_type' => 'free',
                    'quantity' => -$balanceFreeCredits,
                    'balance_after' => $newFreeBalance,
                    'reason' => 'Included free credit reserved while full Stripe refund is pending.',
                    'idempotency_key' => 'refund-reserved-free-credits:'.$created->id,
                ]);
            }
            $affectedBookingIds = array_values(array_unique(array_merge($paidBookingIds, $freeBookingIdsToCancel)));
            if ($affectedBookingIds !== []) {
                Booking::query()->whereIn('id', $affectedBookingIds)->where('status', 'confirmed')->update(['status' => 'refund_pending']);
                foreach (Booking::query()->whereIn('id', $affectedBookingIds)->get() as $affectedBooking) {
                    BookingAudit::query()->create([
                        'booking_id' => $affectedBooking->id,
                        'bundle_entitlement_id' => $lockedEntitlement->id,
                        'actor_id' => $actorId,
                        'action' => 'booking.refund_pending',
                        'reason' => trim($reason),
                        'metadata' => ['refund_id' => $created->id, 'credit_type' => $affectedBooking->credit_type, 'starts_at' => $affectedBooking->starts_at->toIso8601String()],
                    ]);
                }
            }
            BookingAudit::query()->create([
                'bundle_entitlement_id' => $lockedEntitlement->id,
                'actor_id' => $actorId,
                'action' => 'payment.refund_requested',
                'reason' => trim($reason),
                'metadata' => [
                    'payment_id' => $payment->id,
                    'refund_id' => $created->id,
                    'amount_ron' => $amountRon,
                    'paid_credits_to_revoke' => $credits,
                    'balance_paid_credits_reserved' => $balancePaidCredits,
                    'free_credits_to_revoke' => $freeCredits,
                    'affected_booking_ids' => $affectedBookingIds,
                    'full_refund' => $fullRefund,
                ],
            ]);

            return $created;
        }, attempts: 3);

        $payment = $refund->payment()->firstOrFail();

        return $this->submitOrRecoverRefund($refund, $payment);
    }

    public function retryPendingRefund(PaymentRefund $refund): void
    {
        $refund->loadMissing('payment');
        if ($refund->status === 'pending') {
            $this->submitOrRecoverRefund($refund, $refund->payment);
        }
    }

    /** @param array<string, mixed> $providerRefund */
    public function reconcileProviderRefund(array $providerRefund, string $eventId): void
    {
        $metadata = is_array($providerRefund['metadata'] ?? null) ? $providerRefund['metadata'] : [];
        $refundId = is_string($providerRefund['id'] ?? null) ? $providerRefund['id'] : null;
        $localId = filter_var($metadata['payment_refund_id'] ?? null, FILTER_VALIDATE_INT);
        $refund = is_int($localId) && $localId > 0
            ? PaymentRefund::query()->with('payment.entitlement')->find($localId)
            : ($refundId ? PaymentRefund::query()->with('payment.entitlement')->where('provider_refund_id', $refundId)->first() : null);

        if (! $refund) {
            $paymentIntent = is_string($providerRefund['payment_intent'] ?? null) ? $providerRefund['payment_intent'] : null;
            $payment = $paymentIntent ? Payment::query()->where('provider_payment_id', $paymentIntent)->first() : null;
            if ($payment) {
                BookingAudit::query()->create([
                    'bundle_entitlement_id' => $payment->bundle_entitlement_id,
                    'action' => 'payment.external_refund_detected',
                    'metadata' => ['payment_id' => $payment->id, 'stripe_refund_id' => $refundId, 'stripe_event_id' => $eventId, 'provider_status' => $providerRefund['status'] ?? null],
                ]);
            }

            return;
        }

        $payment = $refund->payment;
        if ((int) ($providerRefund['amount'] ?? -1) !== $refund->amount_ron * 100
            || strtolower((string) ($providerRefund['currency'] ?? '')) !== 'ron'
            || (is_string($providerRefund['payment_intent'] ?? null) && $payment->provider_payment_id !== $providerRefund['payment_intent'])) {
            throw new RuntimeException('Stripe refund details do not match the locally authorized refund.');
        }

        $status = (string) ($providerRefund['status'] ?? 'pending');
        $normalizedStatus = match ($status) {
            'succeeded' => 'succeeded',
            'failed', 'canceled' => 'failed',
            default => 'pending',
        };
        if (in_array($refund->status, ['succeeded', 'failed'], true) && $refund->status !== $normalizedStatus) {
            return;
        }
        $refund->forceFill([
            'provider_refund_id' => $refundId ?? $refund->provider_refund_id,
            'provider_status' => $status,
            'metadata' => array_merge($refund->metadata ?? [], ['stripe_event_id' => $eventId, 'provider_metadata' => $metadata]),
        ])->save();

        if ($normalizedStatus === 'succeeded') {
            $this->applySuccessfulRefund($refund);
        } elseif ($normalizedStatus === 'failed') {
            $this->releaseReservedCredits($refund, $status, $eventId);
        } else {
            $refund->forceFill(['status' => 'pending'])->save();
        }
    }

    private function submitOrRecoverRefund(PaymentRefund $refund, Payment $payment): PaymentRefund
    {
        if (is_string($refund->provider_refund_id) && $refund->provider_refund_id !== '') {
            try {
                $providerRefund = $this->stripe->retrieveRefund($refund->provider_refund_id);
            } catch (\Throwable $exception) {
                $refund->forceFill(['metadata' => array_merge($refund->metadata ?? [], ['last_error' => Str::limit($exception->getMessage(), 1000)])])->save();
                throw new RuntimeException('The existing Stripe refund could not be reconciled. Reserved credits remain unavailable until provider status is known.', previous: $exception);
            }
            $this->reconcileProviderRefund($providerRefund, 'server-reconciliation');

            return $refund->refresh();
        }

        try {
            $providerRefund = $this->stripe->createRefund([
                'payment_intent' => $payment->provider_payment_id,
                'amount' => $refund->amount_ron * 100,
                'reason' => 'requested_by_customer',
                'metadata' => [
                    'payment_refund_id' => (string) $refund->id,
                    'payment_id' => (string) $payment->id,
                    'entitlement_id' => (string) $payment->bundle_entitlement_id,
                    'staff_actor_id' => (string) ($refund->actor_id ?? ''),
                ],
            ], $refund->idempotency_key);
        } catch (\Throwable $exception) {
            $refund->forceFill(['metadata' => array_merge($refund->metadata ?? [], ['last_error' => Str::limit($exception->getMessage(), 1000)])])->save();
            throw new RuntimeException('Stripe refund status is uncertain. Credits remain reserved until a safe retry or reconciliation.', previous: $exception);
        }

        $this->reconcileProviderRefund($providerRefund, 'server-reconciliation');

        return $refund->refresh();
    }

    private function applySuccessfulRefund(PaymentRefund $refund): void
    {
        DB::transaction(function () use ($refund): void {
            DB::table('booking_mutexes')->where('id', 1)->lockForUpdate()->first();
            $lockedRefund = PaymentRefund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($lockedRefund->status === 'succeeded') {
                return;
            }
            $payment = Payment::query()->lockForUpdate()->findOrFail($lockedRefund->payment_id);
            $entitlement = BundleEntitlement::query()->lockForUpdate()->findOrFail($payment->bundle_entitlement_id);
            $paidReservation = CreditTransaction::query()->where('idempotency_key', 'refund-reserved-paid-credits:'.$lockedRefund->id)->first();
            if (! $paidReservation) {
                throw new RuntimeException('Refund credit reservation is missing; manual reconciliation is required.');
            }

            $newRefundTotal = $payment->refunded_amount_ron + $lockedRefund->amount_ron;
            if ($newRefundTotal > $payment->amount_ron) {
                throw new RuntimeException('The confirmed refund would exceed the original payment amount.');
            }

            $paidReservation->update(['type' => 'refunded', 'reason' => 'Unused paid credits refunded: '.$lockedRefund->reason]);
            $metadata = $lockedRefund->metadata ?? [];
            $paidBookingIds = is_array($metadata['paid_booking_ids'] ?? null) ? array_map('intval', $metadata['paid_booking_ids']) : [];
            $freeBookingIds = is_array($metadata['free_booking_ids'] ?? null) ? array_map('intval', $metadata['free_booking_ids']) : [];
            if ($lockedRefund->free_credits_to_revoke > 0) {
                $freeReservation = CreditTransaction::query()->where('idempotency_key', 'refund-reserved-free-credits:'.$lockedRefund->id)->firstOrFail();
                $freeReservation->update(['type' => 'revoked', 'reason' => 'Included free credit revoked after full bundle refund.']);
                FreeSessionClaim::query()->where('bundle_entitlement_id', $entitlement->id)
                    ->where(function ($query) use ($freeBookingIds): void {
                        $query->where('status', 'bundle_credit_granted');
                        if ($freeBookingIds !== []) {
                            $query->orWhereIn('booking_id', $freeBookingIds);
                        }
                    })->update(['status' => 'revoked']);
            }

            $affectedBookingIds = array_values(array_unique(array_merge($paidBookingIds, $freeBookingIds)));
            if ($affectedBookingIds !== []) {
                $affectedBookings = Booking::query()->whereIn('id', $affectedBookingIds)->where('status', 'refund_pending')->lockForUpdate()->get();
                foreach ($affectedBookings as $affectedBooking) {
                    $affectedBooking->update([
                        'status' => 'cancelled',
                        'cancelled_at' => now(),
                        'cancelled_by' => 'refund',
                        'cancellation_reason' => 'Bundle refund confirmed by Stripe.',
                    ]);
                    $creditType = $affectedBooking->credit_type ?? 'paid';
                    $balanceAfter = $creditType === 'free' ? $entitlement->free_credits_remaining : $entitlement->credits_remaining;
                    CreditTransaction::query()->firstOrCreate(['idempotency_key' => 'refund-cancel-booking-credit:'.$lockedRefund->id.':'.$affectedBooking->id], [
                        'bundle_entitlement_id' => $entitlement->id,
                        'booking_id' => $affectedBooking->id,
                        'created_by' => $lockedRefund->actor_id,
                        'type' => 'refunded',
                        'credit_type' => $creditType,
                        'quantity' => 0,
                        'balance_after' => $balanceAfter,
                        'reason' => 'Future appointment cancelled because the associated session credit was refunded.',
                    ]);
                    BookingAudit::query()->create([
                        'booking_id' => $affectedBooking->id,
                        'bundle_entitlement_id' => $entitlement->id,
                        'actor_id' => $lockedRefund->actor_id,
                        'action' => 'booking.cancelled_by_refund',
                        'reason' => $lockedRefund->reason,
                        'metadata' => ['refund_id' => $lockedRefund->id, 'credit_type' => $creditType, 'starts_at' => $affectedBooking->starts_at->toIso8601String()],
                    ]);
                    $this->notifications->enqueueBookingEvent($affectedBooking->refresh(), NotificationEvent::BookingCancelled, [
                        'credit_refunded' => true,
                        'credit_restored' => false,
                        'refund_id' => $lockedRefund->id,
                    ], 'refund-'.$lockedRefund->id);
                }
            }

            $payment->update([
                'refunded_amount_ron' => $newRefundTotal,
                'status' => $newRefundTotal >= $payment->amount_ron ? 'refunded' : $payment->status,
            ]);
            if ($payment->status === 'refunded' && $entitlement->credits_remaining === 0 && $entitlement->free_credits_remaining === 0
                && ! $entitlement->bookings()->where(function ($query): void {\n                    $query->whereIn('status', ['refund_pending', 'pending'])->orWhere(function ($query): void {\n                        $query->where('status', 'confirmed')->where('starts_at', '>', now());\n                    });\n                })->exists()) {
                $entitlement->update(['status' => 'refunded']);
            }

            $lockedRefund->update(['status' => 'succeeded', 'confirmed_at' => now()]);
            $this->notifications->enqueueRefundOutcome($lockedRefund->refresh());
            BookingAudit::query()->create([
                'bundle_entitlement_id' => $entitlement->id,
                'actor_id' => $lockedRefund->actor_id,
                'action' => 'payment.refund_succeeded',
                'reason' => $lockedRefund->reason,
                'metadata' => ['payment_id' => $payment->id, 'refund_id' => $lockedRefund->id, 'amount_ron' => $lockedRefund->amount_ron, 'credits_revoked' => $lockedRefund->credits_to_revoke, 'free_credits_revoked' => $lockedRefund->free_credits_to_revoke, 'refunded_total_ron' => $newRefundTotal],
            ]);
        }, attempts: 3);
    }

    private function releaseReservedCredits(PaymentRefund $refund, string $providerStatus, string $eventId): void
    {
        DB::transaction(function () use ($refund, $providerStatus, $eventId): void {
            DB::table('booking_mutexes')->where('id', 1)->lockForUpdate()->first();
            $lockedRefund = PaymentRefund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($lockedRefund->status === 'failed') {
                return;
            }
            $entitlement = BundleEntitlement::query()->lockForUpdate()->findOrFail($lockedRefund->payment->bundle_entitlement_id);
            $metadata = $lockedRefund->metadata ?? [];
            $balancePaidReserved = (int) ($metadata['balance_paid_credits_reserved'] ?? $lockedRefund->credits_to_revoke);
            $balanceFreeReserved = (int) ($metadata['balance_free_credits_reserved'] ?? $lockedRefund->free_credits_to_revoke);
            $paidBalance = $entitlement->credits_remaining + $balancePaidReserved;
            $freeBalance = $entitlement->free_credits_remaining + $balanceFreeReserved;
            $entitlement->update(['credits_remaining' => $paidBalance, 'free_credits_remaining' => $freeBalance]);

            CreditTransaction::query()->create([
                'bundle_entitlement_id' => $entitlement->id,
                'created_by' => $lockedRefund->actor_id,
                'type' => 'refund_released',
                'credit_type' => 'paid',
                'quantity' => $balancePaidReserved,
                'balance_after' => $paidBalance,
                'reason' => 'Stripe refund failed; available balance credits restored.',
                'idempotency_key' => 'refund-release-paid-credits:'.$lockedRefund->id,
            ]);
            if ($lockedRefund->free_credits_to_revoke > 0) {
                CreditTransaction::query()->create([
                    'bundle_entitlement_id' => $entitlement->id,
                    'created_by' => $lockedRefund->actor_id,
                    'type' => 'refund_released',
                    'credit_type' => 'free',
                    'quantity' => $balanceFreeReserved,
                    'balance_after' => $freeBalance,
                    'reason' => 'Stripe refund failed; available free credit restored.',
                    'idempotency_key' => 'refund-release-free-credits:'.$lockedRefund->id,
                ]);
            }
            CreditTransaction::query()->where('idempotency_key', 'refund-reserved-paid-credits:'.$lockedRefund->id)->update(['type' => 'refund_reservation_released']);
            CreditTransaction::query()->where('idempotency_key', 'refund-reserved-free-credits:'.$lockedRefund->id)->update(['type' => 'refund_reservation_released']);
            $affectedBookingIds = array_values(array_unique(array_merge(
                is_array($metadata['paid_booking_ids'] ?? null) ? array_map('intval', $metadata['paid_booking_ids']) : [],
                is_array($metadata['free_booking_ids'] ?? null) ? array_map('intval', $metadata['free_booking_ids']) : [],
            )));
            foreach (Booking::query()->whereIn('id', $affectedBookingIds)->where('status', 'refund_pending')->lockForUpdate()->get() as $affectedBooking) {
                $affectedBooking->update(['status' => 'confirmed']);
                BookingAudit::query()->create([
                    'booking_id' => $affectedBooking->id,
                    'bundle_entitlement_id' => $entitlement->id,
                    'actor_id' => $lockedRefund->actor_id,
                    'action' => 'booking.refund_cancel_reverted',
                    'reason' => 'Stripe refund failed; original appointment remains confirmed.',
                    'metadata' => ['refund_id' => $lockedRefund->id, 'provider_status' => $providerStatus, 'starts_at' => $affectedBooking->starts_at->toIso8601String()],
                ]);
            }
            $lockedRefund->update(['status' => 'failed', 'provider_status' => $providerStatus]);
            $this->notifications->enqueueRefundOutcome($lockedRefund->refresh());
            BookingAudit::query()->create([
                'bundle_entitlement_id' => $entitlement->id,
                'actor_id' => $lockedRefund->actor_id,
                'action' => 'payment.refund_failed',
                'reason' => $lockedRefund->reason,
                'metadata' => ['payment_id' => $lockedRefund->payment_id, 'refund_id' => $lockedRefund->id, 'provider_status' => $providerStatus, 'stripe_event_id' => $eventId, 'credits_restored' => $lockedRefund->credits_to_revoke],
            ]);
        }, attempts: 3);
    }
}
