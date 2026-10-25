<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\BookingAudit;
use App\Models\BundleEntitlement;
use App\Models\CreditTransaction;
use App\Models\FreeSessionClaim;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(private readonly AvailabilityService $availability) {}

    /** @param array{name:string,email:string,phone:string,english_level:string,session_goal:string,notes?:?string,accepted_policy_version:string} $customer */
    public function holdSlot(array $customer, CarbonImmutable $startsAt, ?int $entitlementId = null, bool $freeIntroduction = false, bool $emailVerified = false, bool $phoneVerified = false): Booking
    {
        $customer = Validator::make($customer, [
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:254'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9\s().-]{7,32}$/'],
            'english_level' => ['required', 'in:A1,A2,B1,B2,C1,C2'],
            'session_goal' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'accepted_policy_version' => ['required', 'string', 'max:64'],
        ])->validate();
        $customer['phone'] = PhoneNumber::normalize($customer['phone']);

        return DB::transaction(function () use ($customer, $startsAt, $entitlementId, $freeIntroduction, $emailVerified, $phoneVerified): Booking {
            // A singleton row serializes booking mutations even when the requested slot has no existing row to lock.
            DB::table('booking_mutexes')->where('id', 1)->lockForUpdate()->first();
            $this->availability->assertBookable($startsAt);

            if ($freeIntroduction === ($entitlementId !== null)) {
                throw ValidationException::withMessages(['booking_type' => 'Choose either a free introduction or a paid bundle credit.']);
            }

            $entitlement = null;
            $claim = null;
            $bundleFreeClaim = null;
            $creditType = null;
            if ($freeIntroduction) {
                if (! $emailVerified || ! $phoneVerified) {
                    throw ValidationException::withMessages(['verification' => 'Email and phone must be verified before claiming the free introduction.']);
                }
                $existing = FreeSessionClaim::query()->where(function ($query) use ($customer): void {
                    $query->where('customer_email', mb_strtolower($customer['email']))->orWhere('customer_phone', $customer['phone']);
                })->exists();
                if ($existing) {
                    throw ValidationException::withMessages(['free_introduction' => 'The free introductory session has already been claimed for these contact details.']);
                }
            } else {
                $entitlement = BundleEntitlement::query()->lockForUpdate()->findOrFail($entitlementId);
                if ($entitlement->status !== 'active' || $entitlement->expires_at === null || $entitlement->expires_at->lessThanOrEqualTo($startsAt->utc()) || ($entitlement->credits_remaining < 1 && $entitlement->free_credits_remaining < 1)) {
                    throw ValidationException::withMessages(['bundle' => 'This bundle has no usable credits or is outside its validity period.']);
                }
                if (mb_strtolower(trim($customer['email'])) !== mb_strtolower($entitlement->customer_email)
                    || preg_replace('/\\D+/', '', $customer['phone']) !== preg_replace('/\\D+/', '', $entitlement->customer_phone)) {
                    throw ValidationException::withMessages(['bundle' => 'The booking contact details must match the purchased bundle.']);
                }
                $creditType = $entitlement->free_credits_remaining > 0 ? 'free' : 'paid';
                if ($creditType === 'free') {
                    $bundleFreeClaim = FreeSessionClaim::query()->where('bundle_entitlement_id', $entitlement->id)->where('status', 'bundle_credit_granted')->whereNull('booking_id')->lockForUpdate()->first();
                    if (! $bundleFreeClaim) {
                        throw ValidationException::withMessages(['bundle' => 'The included free-session allowance is already claimed or inconsistent.']);
                    }
                    $entitlement->decrement('free_credits_remaining');
                } else {
                    $entitlement->decrement('credits_remaining');
                }
            }

            $startUtc = $startsAt->utc();
            $booking = Booking::query()->create([
                'public_reference' => (string) Str::uuid(),
                'bundle_entitlement_id' => $entitlement?->id,
                'customer_name' => trim($customer['name']),
                'customer_email' => mb_strtolower(trim($customer['email'])),
                'customer_phone' => trim($customer['phone']),
                'english_level' => $customer['english_level'],
                'session_goal' => trim($customer['session_goal']),
                'notes' => $customer['notes'] ?? null,
                'accepted_policy_version' => $customer['accepted_policy_version'],
                'starts_at' => $startUtc,
                'ends_at' => $startUtc->addMinutes(AvailabilityService::SESSION_MINUTES),
                'duration_minutes' => AvailabilityService::SESSION_MINUTES,
                'status' => 'pending',
                'credit_type' => $creditType,
                'hold_expires_at' => now()->addMinutes(5),
            ]);

            if ($freeIntroduction) {
                $claim = FreeSessionClaim::query()->create([
                    'customer_email' => mb_strtolower(trim($customer['email'])),
                    'customer_phone' => trim($customer['phone']),
                    'status' => 'reserved',
                    'booking_id' => $booking->id,
                    'verified_at' => now(),
                ]);
                $booking->update(['free_session_claim_id' => $claim->id]);
            } elseif ($bundleFreeClaim) {
                $bundleFreeClaim->update(['booking_id' => $booking->id, 'status' => 'bundle_credit_reserved']);
                $booking->update(['free_session_claim_id' => $bundleFreeClaim->id]);
                CreditTransaction::query()->create([
                    'bundle_entitlement_id' => $entitlement->id,
                    'booking_id' => $booking->id,
                    'type' => 'reserved',
                    'credit_type' => 'free',
                    'quantity' => -1,
                    'balance_after' => $entitlement->refresh()->free_credits_remaining,
                    'reason' => 'Included free credit reserved for a five-minute booking hold.',
                    'idempotency_key' => 'booking-hold:'.$booking->public_reference,
                ]);
            } else {
                CreditTransaction::query()->create([
                    'bundle_entitlement_id' => $entitlement->id,
                    'booking_id' => $booking->id,
                    'type' => 'reserved',
                    'credit_type' => $creditType,
                    'quantity' => -1,
                    'balance_after' => $creditType === 'free' ? $entitlement->refresh()->free_credits_remaining : $entitlement->refresh()->credits_remaining,
                    'reason' => 'Credit reserved for a five-minute booking hold.',
                    'idempotency_key' => 'booking-hold:'.$booking->public_reference,
                ]);
            }

            BookingAudit::query()->create([
                'booking_id' => $booking->id,
                'bundle_entitlement_id' => $entitlement?->id,
                'action' => 'booking.held',
                'metadata' => ['hold_expires_at' => $booking->hold_expires_at?->toIso8601String()],
            ]);

            return $booking->refresh();
        }, attempts: 3);
    }

    public function confirm(int $bookingId): Booking
    {
        return DB::transaction(function () use ($bookingId): Booking {
            DB::table('booking_mutexes')->where('id', 1)->lockForUpdate()->first();
            $booking = Booking::query()->lockForUpdate()->findOrFail($bookingId);
            if ($booking->status === 'confirmed') {
                return $booking;
            }
            if ($booking->status !== 'pending' || $booking->hold_expires_at === null || $booking->hold_expires_at->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages(['booking' => 'This booking hold has expired or is no longer confirmable.']);
            }
            $booking->update(['status' => 'confirmed', 'confirmed_at' => now(), 'hold_expires_at' => null]);
            if ($booking->bundle_entitlement_id) {
                CreditTransaction::query()->create([
                    'bundle_entitlement_id' => $booking->bundle_entitlement_id,
                    'booking_id' => $booking->id,
                    'type' => 'consumed',
                    'credit_type' => $booking->credit_type ?? 'paid',
                    'quantity' => 0,
                    'balance_after' => $booking->credit_type === 'free' ? $booking->entitlement->refresh()->free_credits_remaining : $booking->entitlement->refresh()->credits_remaining,
                    'reason' => 'Reserved credit committed when booking was confirmed.',
                    'idempotency_key' => 'booking-confirmed:'.$booking->public_reference,
                ]);
            }
            if ($booking->free_session_claim_id) {
                FreeSessionClaim::query()->whereKey($booking->free_session_claim_id)->update(['status' => 'used', 'used_at' => now()]);
            }
            BookingAudit::query()->create(['booking_id' => $booking->id, 'bundle_entitlement_id' => $booking->bundle_entitlement_id, 'action' => 'booking.confirmed']);

            return $booking->refresh();
        }, attempts: 3);
    }

    public function expireHolds(): int
    {
        $expired = 0;
        Booking::query()->where('status', 'pending')->where('hold_expires_at', '<=', now())->orderBy('id')->chunkById(100, function ($bookings) use (&$expired): void {
            foreach ($bookings as $candidate) {
                DB::transaction(function () use ($candidate, &$expired): void {
                    DB::table('booking_mutexes')->where('id', 1)->lockForUpdate()->first();
                    $booking = Booking::query()->lockForUpdate()->find($candidate->id);
                    if (! $booking || $booking->status !== 'pending' || ! $booking->hold_expires_at?->lessThanOrEqualTo(now())) {
                        return;
                    }
                    if ($booking->bundle_entitlement_id) {
                        $entitlement = BundleEntitlement::query()->lockForUpdate()->findOrFail($booking->bundle_entitlement_id);
                        if ($booking->credit_type === 'free') {
                            $entitlement->increment('free_credits_remaining');
                        } else {
                            $entitlement->increment('credits_remaining');
                        }
                        CreditTransaction::query()->firstOrCreate(['idempotency_key' => 'booking-expired:'.$booking->public_reference], [
                            'bundle_entitlement_id' => $entitlement->id, 'booking_id' => $booking->id, 'type' => 'restored', 'credit_type' => $booking->credit_type ?? 'paid', 'quantity' => 1,
                            'balance_after' => $booking->credit_type === 'free' ? $entitlement->refresh()->free_credits_remaining : $entitlement->refresh()->credits_remaining, 'reason' => 'Expired hold released its reserved credit.',
                        ]);
                    }
                    if ($booking->free_session_claim_id && $booking->bundle_entitlement_id && $booking->credit_type === 'free') {
                        FreeSessionClaim::query()->whereKey($booking->free_session_claim_id)->update(['booking_id' => null, 'status' => 'bundle_credit_granted', 'used_at' => null]);
                    } elseif ($booking->free_session_claim_id) {
                        FreeSessionClaim::query()->whereKey($booking->free_session_claim_id)->delete();
                        $this->grantDeferredBundleFreeCredit($booking->customer_email, $booking->customer_phone);
                    }
                    $booking->update(['status' => 'expired', 'expired_at' => now(), 'hold_expires_at' => null]);
                    BookingAudit::query()->create(['booking_id' => $booking->id, 'bundle_entitlement_id' => $booking->bundle_entitlement_id, 'action' => 'booking.hold_expired']);
                    $expired++;
                }, attempts: 3);
            }
        });

        return $expired;
    }

    private function grantDeferredBundleFreeCredit(string $email, string $phone): void
    {
        $alreadyClaimed = FreeSessionClaim::query()->where(function ($query) use ($email, $phone): void {
            $query->where('customer_email', mb_strtolower($email))->orWhere('customer_phone', $phone);
        })->exists();
        if ($alreadyClaimed) {
            return;
        }

        $entitlement = BundleEntitlement::query()->with('definition')->where('status', 'active')
            ->where(function ($query) use ($email, $phone): void {
                $query->where('customer_email', mb_strtolower($email))->orWhere('customer_phone', $phone);
            })->orderBy('activated_at')->orderBy('id')->lockForUpdate()->get()
            ->first(fn (BundleEntitlement $candidate): bool => $candidate->customer_email_verified_at !== null
                && $candidate->customer_phone_verified_at !== null
                && $candidate->free_credits_granted < $candidate->definition->included_free_credits);
        if (! $entitlement) {
            return;
        }

        $entitlement->increment('free_credits_remaining');
        $entitlement->increment('free_credits_granted');
        FreeSessionClaim::query()->create([
            'customer_email' => mb_strtolower($entitlement->customer_email),
            'customer_phone' => $entitlement->customer_phone,
            'status' => 'bundle_credit_granted',
            'bundle_entitlement_id' => $entitlement->id,
            'verified_at' => now(),
        ]);
        CreditTransaction::query()->create([
            'bundle_entitlement_id' => $entitlement->id,
            'type' => 'granted',
            'credit_type' => 'free',
            'quantity' => 1,
            'balance_after' => $entitlement->refresh()->free_credits_remaining,
            'reason' => 'Included free credit granted after an unconfirmed standalone introduction hold expired.',
            'idempotency_key' => 'bundle-free-grant:'.$entitlement->id,
        ]);
        BookingAudit::query()->create([
            'bundle_entitlement_id' => $entitlement->id,
            'action' => 'bundle.free_credit_granted_after_hold_expiry',
        ]);
    }
}
