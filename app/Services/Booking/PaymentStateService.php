<?php

namespace App\Services\Booking;

use App\Enums\PaymentStatus;
use App\Models\BookingAudit;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentStateService
{
    /** Call only after a trusted provider event or server-side reconciliation has been verified. */
    public function transitionAfterVerifiedProviderEvent(Payment $payment, PaymentStatus $target): Payment
    {
        return DB::transaction(function () use ($payment, $target): Payment {
            DB::table('booking_mutexes')->where('id', 1)->lockForUpdate()->first();
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $current = PaymentStatus::from($lockedPayment->status);

            if ($current === $target) {
                return $lockedPayment;
            }

            if ($target === PaymentStatus::Refunded) {
                throw ValidationException::withMessages(['payment' => 'Refunds must use the audited refund workflow so bundle credits and statutory refund rules remain consistent.']);
            }

            if (! $current->canTransitionTo($target)) {
                throw ValidationException::withMessages(['payment' => "Payment cannot transition from {$current->value} to {$target->value}."]);
            }

            if ($target === PaymentStatus::Succeeded) {
                $entitlement = $lockedPayment->entitlement()->lockForUpdate()->firstOrFail();
                if ($lockedPayment->amount_ron !== $entitlement->paid_amount_ron || $lockedPayment->currency !== 'RON' || $lockedPayment->amount_ron !== $entitlement->definition->price_ron) {
                    throw ValidationException::withMessages(['payment' => 'The verified payment amount does not match the configured bundle price.']);
                }
            }

            $lockedPayment->update([
                'status' => $target->value,
                'confirmed_at' => $target === PaymentStatus::Succeeded ? now() : $lockedPayment->confirmed_at,
            ]);

            BookingAudit::query()->create([
                'bundle_entitlement_id' => $lockedPayment->bundle_entitlement_id,
                'action' => 'payment.status_changed',
                'metadata' => ['payment_id' => $lockedPayment->id, 'from' => $current->value, 'to' => $target->value],
            ]);

            if ($target === PaymentStatus::Succeeded) {
                app(BundleEntitlementService::class)->activateAfterVerifiedPayment($lockedPayment->bundle_entitlement_id);
            }

            return $lockedPayment->refresh();
        }, attempts: 3);
    }
}
