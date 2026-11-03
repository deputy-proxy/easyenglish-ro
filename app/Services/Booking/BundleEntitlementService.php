<?php

namespace App\Services\Booking;

use App\Enums\NotificationEvent;
use App\Models\BookingAudit;
use App\Models\BundleEntitlement;
use App\Models\CreditTransaction;
use App\Services\Notifications\NotificationOutboxService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BundleEntitlementService
{
    public function __construct(private readonly NotificationOutboxService $notifications) {}

    /** Call only after a trusted payment-provider confirmation has been verified. */
    public function activateAfterVerifiedPayment(int $entitlementId): BundleEntitlement
    {
        return DB::transaction(function () use ($entitlementId): BundleEntitlement {
            DB::table('booking_mutexes')->where('id', 1)->lockForUpdate()->first();
            $entitlement = BundleEntitlement::query()->with('definition')->lockForUpdate()->findOrFail($entitlementId);
            if ($entitlement->status === 'active') {
                return $entitlement;
            }
            if ($entitlement->status !== 'pending_payment') {
                throw ValidationException::withMessages(['bundle' => 'Only a pending bundle purchase can be activated.']);
            }
            if ($entitlement->paid_amount_ron < 1 || $entitlement->currency !== 'RON' || $entitlement->paid_credits !== $entitlement->definition->paid_credits) {
                throw ValidationException::withMessages(['bundle' => 'The stored bundle amount or credit quantity does not match the configured product.']);
            }

            $activatedAt = CarbonImmutable::now(AvailabilityService::TIMEZONE);
            $expiresAt = $activatedAt->addMonthsNoOverflow(3);
            $freeCredits = 0;

            $entitlement->update([
                'status' => 'active',
                'activated_at' => $activatedAt->utc(),
                'expires_at' => $expiresAt->utc(),
                'credits_remaining' => $entitlement->paid_credits,
                'free_credits_granted' => $freeCredits,
                'free_credits_remaining' => $freeCredits,
            ]);

            CreditTransaction::query()->create([
                'bundle_entitlement_id' => $entitlement->id,
                'type' => 'granted',
                'credit_type' => 'paid',
                'quantity' => $entitlement->paid_credits,
                'balance_after' => $entitlement->paid_credits,
                'reason' => 'Paid credits granted after verified payment.',
                'idempotency_key' => 'bundle-paid-grant:'.$entitlement->id,
            ]);

            BookingAudit::query()->create([
                'bundle_entitlement_id' => $entitlement->id,
                'action' => 'bundle.activated',
                'metadata' => ['expires_at' => $expiresAt->utc()->toIso8601String(), 'free_credits_granted' => $freeCredits],
            ]);
            $this->notifications->enqueueEntitlementEvent($entitlement->refresh(), NotificationEvent::BundlePurchaseConfirmed);

            return $entitlement->refresh();
        }, attempts: 3);
    }

    /** Apply an auditable staff adjustment to paid credits only. */
    public function adjustPaidCredits(int $entitlementId, int $quantity, int $actorId, string $reason): BundleEntitlement
    {
        if ($quantity === 0 || mb_strlen(trim($reason)) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['adjustment' => 'Enter a non-zero integer adjustment and a reason of at least 10 characters.']);
        }

        return DB::transaction(function () use ($entitlementId, $quantity, $actorId, $reason): BundleEntitlement {
            DB::table('booking_mutexes')->where('id', 1)->lockForUpdate()->first();
            $entitlement = BundleEntitlement::query()->lockForUpdate()->findOrFail($entitlementId);
            if ($entitlement->status !== 'active' || $entitlement->credits_remaining + $quantity < 0) {
                throw ValidationException::withMessages(['adjustment' => 'Only active bundles can be adjusted, and the paid credit balance cannot become negative.']);
            }

            $entitlement->increment('credits_remaining', $quantity);
            $balance = $entitlement->refresh()->credits_remaining;
            CreditTransaction::query()->create([
                'bundle_entitlement_id' => $entitlement->id,
                'created_by' => $actorId,
                'type' => 'adjusted',
                'credit_type' => 'paid',
                'quantity' => $quantity,
                'balance_after' => $balance,
                'reason' => trim($reason),
                'idempotency_key' => 'staff-adjustment:'.Str::uuid(),
            ]);
            BookingAudit::query()->create([
                'bundle_entitlement_id' => $entitlement->id,
                'actor_id' => $actorId,
                'action' => 'bundle.paid_credits_adjusted',
                'reason' => trim($reason),
                'metadata' => ['quantity' => $quantity, 'balance_after' => $balance],
            ]);

            return $entitlement;
        }, attempts: 3);
    }
}
