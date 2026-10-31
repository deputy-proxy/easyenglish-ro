<?php

namespace App\Services\Booking;

use App\Enums\NotificationEvent;
use App\Jobs\Calendar\SyncBookingCalendarEvent;
use App\Models\Booking;
use App\Models\BookingAudit;
use App\Models\BundleEntitlement;
use App\Models\CreditTransaction;
use App\Models\FreeSessionClaim;
use App\Models\User;
use App\Services\Notifications\NotificationOutboxService;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(private readonly AvailabilityService $availability, private readonly NotificationOutboxService $notifications, private readonly CancellationPolicyService $policy) {}

    /** @param array{name:string,email:string,phone:string,english_level:string,session_goal:string,notes?:?string,accepted_policy_version:string} $customer */
    public function holdSlot(array $customer, CarbonImmutable $startsAt, ?int $entitlementId = null, bool $freeIntroduction = false, bool $emailVerified = false, bool $phoneVerified = false): Booking
    {
        $customer = Validator::make($customer, [
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:254'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9\s().-]{7,32}$/', function (string $attribute, mixed $value, Closure $fail): void {
                $digits = preg_replace('/\\D+/', '', (string) $value) ?? '';
                if (strlen($digits) < 7 || strlen($digits) > 15) {
                    $fail('Enter a phone number containing 7 to 15 digits.');
                }
            }],
            'english_level' => ['required', 'in:A1,A2,B1,B2,C1,C2'],
            'session_goal' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'accepted_policy_version' => ['required', 'string', 'max:64'],
            'locale' => ['sometimes', 'in:ro,en'],
        ])->validate();
        $customer['phone'] = PhoneNumber::normalize($customer['phone']);
        $customer['locale'] = $customer['locale'] ?? 'ro';

        return DB::transaction(function () use ($customer, $startsAt, $entitlementId, $freeIntroduction, $emailVerified, $phoneVerified): Booking {
            // A singleton row serializes booking mutations even when the requested slot has no existing row to lock.
            DB::table('booking_mutexes')->where('id', 1)->lockForUpdate()->first();
            $assignedTeacher = null;
            if ($this->availability->teacherAvailabilityEnabled()) {
                $assignedTeacher = $this->availability->eligibleTeachersForSlot($startsAt)->first();
                if ($assignedTeacher === null) {
                    throw ValidationException::withMessages(['starts_at' => 'That time is no longer available. Please choose another slot.']);
                }
            } else {
                $this->availability->assertBookable($startsAt);
            }

            if ($freeIntroduction === ($entitlementId !== null)) {
                throw ValidationException::withMessages(['booking_type' => 'Choose either a free introduction or a paid bundle credit.']);
            }

            $entitlement = null;
            $claim = null;
            $reusableClaim = null;
            $bundleFreeClaim = null;
            $creditType = null;
            if ($freeIntroduction) {
                $emailOnly = config('services.contact_verification.mode', 'email_and_whatsapp') === 'email_only';
                if (! $emailVerified || (! $emailOnly && ! $phoneVerified)) {
                    throw ValidationException::withMessages(['verification' => $emailOnly
                        ? 'Email must be verified before claiming the free introduction.'
                        : 'Email and phone must be verified before claiming the free introduction.']);
                }
                $reusableClaim = FreeSessionClaim::query()->where(function ($query) use ($customer): void {
                    $query->where('customer_email', mb_strtolower($customer['email']))->orWhere('customer_phone', $customer['phone']);
                })->lockForUpdate()->first();
                if ($reusableClaim !== null && ($reusableClaim->status !== 'teacher_cancelled' || $reusableClaim->booking_id !== null)) {
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
                'teacher_id' => $assignedTeacher?->id,
                'teacher_assigned_at' => $assignedTeacher ? now() : null,
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
                'locale' => $customer['locale'],
                'status' => 'pending',
                'credit_type' => $creditType,
                'hold_expires_at' => now()->addMinutes(5),
            ]);

            if ($freeIntroduction) {
                if ($reusableClaim !== null) {
                    $claim = $reusableClaim;
                    $claim->update(['status' => 'reserved', 'booking_id' => $booking->id, 'used_at' => null]);
                } else {
                    $claim = FreeSessionClaim::query()->create([
                        'customer_email' => mb_strtolower(trim($customer['email'])),
                        'customer_phone' => trim($customer['phone']),
                        'status' => 'reserved',
                        'booking_id' => $booking->id,
                        'verified_at' => now(),
                    ]);
                }
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
                'metadata' => [
                    'hold_expires_at' => $booking->hold_expires_at?->toIso8601String(),
                    'teacher_id' => $booking->teacher_id,
                    'teacher_assigned_at' => $booking->teacher_assigned_at?->toIso8601String(),
                ],
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
                $this->notifications->enqueueBookingConfirmation($booking);
                if ($booking->teacher_id !== null) {
                    SyncBookingCalendarEvent::dispatch($booking->id)->afterCommit();
                }

                return $booking;
            }
            if ($booking->status !== 'pending' || $booking->hold_expires_at === null || $booking->hold_expires_at->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages(['booking' => 'This booking hold has expired or is no longer confirmable.']);
            }
            $previousTeacherId = $booking->teacher_id;
            if ($this->availability->teacherAvailabilityEnabled()) {
                $eligibleTeachers = $this->availability->eligibleTeachersForSlot($booking->starts_at, $booking->id);
                if ($eligibleTeachers->isEmpty()) {
                    throw ValidationException::withMessages(['booking' => 'No teacher can fulfil this time any longer. Please choose another slot.']);
                }
                if (! $eligibleTeachers->contains('id', $booking->teacher_id)) {
                    $teacher = $eligibleTeachers->first();
                    $booking->update(['teacher_id' => $teacher->id, 'teacher_assigned_at' => now()]);
                }
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
            BookingAudit::query()->create([
                'booking_id' => $booking->id,
                'bundle_entitlement_id' => $booking->bundle_entitlement_id,
                'action' => 'booking.confirmed',
                'metadata' => [
                    'previous_teacher_id' => $previousTeacherId,
                    'teacher_id' => $booking->teacher_id,
                    'teacher_reassigned_at_confirmation' => $previousTeacherId !== $booking->teacher_id,
                ],
            ]);
            $this->notifications->enqueueBookingConfirmation($booking->refresh());
            if ($booking->teacher_id !== null) {
                SyncBookingCalendarEvent::dispatch($booking->id)->afterCommit();
            }

            return $booking->refresh();
        }, attempts: 3);
    }

    public function cancelByCustomer(int $bookingId, string $reason = 'Customer cancellation request.'): Booking
    {
        return $this->cancelCustomerRequest($bookingId, null, $reason);
    }

    public function cancelAtCustomerRequest(int $bookingId, int $actorId, string $reason): Booking
    {
        $this->assertStaff($actorId);

        return $this->cancelCustomerRequest($bookingId, $actorId, $reason);
    }

    private function cancelCustomerRequest(int $bookingId, ?int $actorId, string $reason): Booking
    {
        $reason = trim($reason) !== '' ? trim($reason) : 'Customer cancellation request.';

        return DB::transaction(function () use ($bookingId, $actorId, $reason): Booking {
            DB::table('booking_mutexes')->where('id', 1)->lockForUpdate()->first();
            $booking = Booking::query()->lockForUpdate()->findOrFail($bookingId);
            if ($booking->status === 'cancelled') {
                return $booking;
            }
            if ($booking->status !== 'confirmed') {
                throw ValidationException::withMessages(['booking' => 'Only a confirmed appointment can be cancelled.']);
            }

            $now = CarbonImmutable::now('UTC');
            if ($booking->starts_at->lessThanOrEqualTo($now)) {
                throw ValidationException::withMessages(['booking' => 'An appointment that has started cannot be cancelled as a customer cancellation. Ask staff to review it.']);
            }
            $outcome = $this->policy->customerCancellationOutcome($booking, $now);
            $creditRestored = false;
            if ($booking->bundle_entitlement_id !== null) {
                $entitlement = BundleEntitlement::query()->lockForUpdate()->findOrFail($booking->bundle_entitlement_id);
                if ($outcome === 'restore') {
                    $this->restoreReservedCredit($booking, $entitlement, $actorId, 'Customer cancellation at least 24 hours before the appointment.', 'booking-cancel-restored:'.$booking->public_reference);
                    $creditRestored = true;
                } else {
                    $this->recordForfeitedCredit($booking, $entitlement, $actorId, 'Customer cancellation less than 24 hours before the appointment.', 'booking-cancel-forfeited:'.$booking->public_reference);
                }
                if ($booking->credit_type === 'free' && $creditRestored && $booking->free_session_claim_id !== null) {
                    FreeSessionClaim::query()->whereKey($booking->free_session_claim_id)->update(['status' => 'bundle_credit_granted', 'booking_id' => null, 'used_at' => null]);
                }
            } elseif ($booking->free_session_claim_id !== null) {
                // Cancelling a free introduction never creates a second free offer.
                FreeSessionClaim::query()->whereKey($booking->free_session_claim_id)->update(['status' => 'cancelled', 'used_at' => now()]);
            }

            $booking->update([
                'status' => 'cancelled',
                'cancelled_at' => $now,
                'cancelled_by' => $actorId === null ? 'customer' : 'staff_customer_request',
                'cancellation_reason' => $reason,
            ]);
            BookingAudit::query()->create([
                'booking_id' => $booking->id,
                'bundle_entitlement_id' => $booking->bundle_entitlement_id,
                'actor_id' => $actorId,
                'action' => $actorId === null ? 'booking.customer_cancelled' : 'booking.customer_request_cancelled_by_staff',
                'reason' => $reason,
                'metadata' => [
                    'credit_restored' => $creditRestored,
                    'credit_outcome' => $outcome,
                    'accepted_policy_version' => $booking->accepted_policy_version,
                    'seconds_before_start' => $this->policy->secondsUntilStart($booking, $now),
                ],
            ]);
            $this->notifications->enqueueBookingEvent($booking->refresh(), NotificationEvent::BookingCancelled, [
                'credit_restored' => $creditRestored,
                'credit_refunded' => false,
                'cancellation_reason' => $reason,
            ], 'customer-cancelled');
            if ($booking->teacher_id !== null) {
                SyncBookingCalendarEvent::dispatch($booking->id)->afterCommit();
            }

            return $booking->refresh();
        }, attempts: 3);
    }

    public function rescheduleByCustomer(int $bookingId, CarbonImmutable $newStartsAt): Booking
    {
        return $this->rescheduleBooking($bookingId, $newStartsAt, null, null);
    }

    public function rescheduleByStaff(int $bookingId, CarbonImmutable $newStartsAt, int $actorId, string $reason): Booking
    {
        $this->assertStaff($actorId);
        if (mb_strlen(trim($reason)) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Provide a rescheduling reason between 10 and 500 characters.']);
        }

        return $this->rescheduleBooking($bookingId, $newStartsAt, $actorId, trim($reason));
    }

    private function rescheduleBooking(int $bookingId, CarbonImmutable $newStartsAt, ?int $actorId, ?string $reason): Booking
    {
        return DB::transaction(function () use ($bookingId, $newStartsAt, $actorId, $reason): Booking {
            DB::table('booking_mutexes')->where('id', 1)->lockForUpdate()->first();
            $booking = Booking::query()->lockForUpdate()->findOrFail($bookingId);
            if ($booking->status !== 'confirmed') {
                throw ValidationException::withMessages(['booking' => 'Only a confirmed appointment can be rescheduled.']);
            }
            $newUtc = $newStartsAt->utc();
            if ($booking->starts_at->equalTo($newUtc)) {
                return $booking;
            }
            $now = CarbonImmutable::now('UTC');
            if ($booking->starts_at->lessThanOrEqualTo($now) || $newUtc->lessThanOrEqualTo($now)) {
                throw ValidationException::withMessages(['starts_at' => 'The current appointment must be in the future and the replacement slot cannot be in the past.']);
            }
            if ($actorId === null) {
                $actions = $this->policy->customerActions($booking, $now);
                if (! $actions['can_reschedule']) {
                    throw ValidationException::withMessages(['booking' => 'A free customer reschedule is available once, between 12 and 24 hours before the appointment.']);
                }
            }
            $assignedTeacher = null;
            if ($this->availability->teacherAvailabilityEnabled()) {
                $eligibleTeachers = $this->availability->eligibleTeachersForSlot($newUtc, $booking->id);
                $assignedTeacher = $eligibleTeachers->firstWhere('id', $booking->teacher_id) ?? $eligibleTeachers->first();
                if ($assignedTeacher === null) {
                    throw ValidationException::withMessages(['starts_at' => 'That time is no longer available. Please choose another slot.']);
                }
            } else {
                $this->availability->assertBookable($newUtc, $booking->id);
            }
            if ($booking->bundle_entitlement_id !== null) {
                $entitlement = BundleEntitlement::query()->lockForUpdate()->findOrFail($booking->bundle_entitlement_id);
                if ($entitlement->status !== 'active' || $entitlement->expires_at === null || $entitlement->expires_at->lessThanOrEqualTo($newUtc)) {
                    throw ValidationException::withMessages(['starts_at' => 'The replacement session must start before the active bundle expires.']);
                }
            }

            $previousStart = $booking->starts_at;
            $previousTeacherId = $booking->teacher_id;
            $teacherChanged = $assignedTeacher !== null && $assignedTeacher->id !== $booking->teacher_id;
            $booking->update([
                'starts_at' => $newUtc,
                'ends_at' => $newUtc->addMinutes(AvailabilityService::SESSION_MINUTES),
                'teacher_id' => $assignedTeacher !== null ? $assignedTeacher->id : $booking->teacher_id,
                'teacher_assigned_at' => $teacherChanged ? now() : $booking->teacher_assigned_at,
                'reschedule_count' => $booking->reschedule_count + 1,
                'rescheduled_at' => $now,
            ]);
            BookingAudit::query()->create([
                'booking_id' => $booking->id,
                'bundle_entitlement_id' => $booking->bundle_entitlement_id,
                'actor_id' => $actorId,
                'action' => $actorId === null ? 'booking.customer_rescheduled' : 'booking.staff_rescheduled',
                'reason' => $reason,
                'metadata' => [
                    'previous_starts_at' => $previousStart->toIso8601String(),
                    'new_starts_at' => $newUtc->toIso8601String(),
                    'reschedule_count' => $booking->reschedule_count,
                    'accepted_policy_version' => $booking->accepted_policy_version,
                    'previous_teacher_id' => $previousTeacherId,
                    'teacher_id' => $booking->teacher_id,
                    'teacher_reassigned' => $teacherChanged,
                ],
            ]);
            $this->notifications->enqueueBookingEvent($booking->refresh(), NotificationEvent::BookingRescheduled, [
                'previous_starts_at' => $previousStart->toIso8601String(),
                'reason' => $reason,
            ], $newUtc->toIso8601String());
            if ($booking->teacher_id !== null) {
                SyncBookingCalendarEvent::dispatch($booking->id)->afterCommit();
            }

            return $booking->refresh();
        }, attempts: 3);
    }

    public function cancelByTeacher(int $bookingId, int $actorId, string $reason): Booking
    {
        $this->assertStaff($actorId);
        if (mb_strlen(trim($reason)) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Provide a teacher-cancellation reason between 10 and 500 characters.']);
        }

        return DB::transaction(function () use ($bookingId, $actorId, $reason): Booking {
            DB::table('booking_mutexes')->where('id', 1)->lockForUpdate()->first();
            $booking = Booking::query()->lockForUpdate()->findOrFail($bookingId);
            if ($booking->status === 'cancelled' && $booking->cancelled_by === 'teacher') {
                return $booking;
            }
            if ($booking->status !== 'confirmed' || $booking->starts_at->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
                throw ValidationException::withMessages(['booking' => 'Only a future confirmed appointment can be cancelled by a teacher.']);
            }

            $now = CarbonImmutable::now('UTC');
            $creditRestored = false;
            $validityExtendedTo = null;
            if ($booking->bundle_entitlement_id !== null) {
                $entitlement = BundleEntitlement::query()->lockForUpdate()->findOrFail($booking->bundle_entitlement_id);
                $this->restoreReservedCredit($booking, $entitlement, $actorId, 'Teacher cancellation restores the reserved session credit.', 'teacher-cancel-restored:'.$booking->public_reference);
                $creditRestored = true;
                $minimumExpiry = $now->addDays(14);
                if ($entitlement->expires_at !== null && $entitlement->expires_at->lessThan($minimumExpiry)) {
                    $entitlement->update(['expires_at' => $minimumExpiry]);
                    $validityExtendedTo = $minimumExpiry->toIso8601String();
                }
                if ($booking->credit_type === 'free' && $booking->free_session_claim_id !== null) {
                    FreeSessionClaim::query()->whereKey($booking->free_session_claim_id)->update(['status' => 'bundle_credit_granted', 'booking_id' => null, 'used_at' => null]);
                }
            } elseif ($booking->free_session_claim_id !== null) {
                // Reuse the existing claim for one replacement booking; never create a second free offer.
                FreeSessionClaim::query()->whereKey($booking->free_session_claim_id)->update(['status' => 'teacher_cancelled', 'booking_id' => null, 'used_at' => null]);
            }

            $booking->update([
                'status' => 'cancelled',
                'cancelled_at' => $now,
                'cancelled_by' => 'teacher',
                'cancellation_reason' => trim($reason),
            ]);
            BookingAudit::query()->create([
                'booking_id' => $booking->id,
                'bundle_entitlement_id' => $booking->bundle_entitlement_id,
                'actor_id' => $actorId,
                'action' => 'booking.teacher_cancelled',
                'reason' => trim($reason),
                'metadata' => ['credit_restored' => $creditRestored, 'validity_extended_to' => $validityExtendedTo, 'accepted_policy_version' => $booking->accepted_policy_version],
            ]);
            $this->notifications->enqueueBookingEvent($booking->refresh(), NotificationEvent::TeacherCancellation, [
                'credit_restored' => $creditRestored,
                'validity_extended_to' => $validityExtendedTo,
            ], 'teacher-cancelled');
            if ($booking->teacher_id !== null) {
                SyncBookingCalendarEvent::dispatch($booking->id)->afterCommit();
            }

            return $booking->refresh();
        }, attempts: 3);
    }

    public function markNoShow(int $bookingId, int $actorId, string $reason): Booking
    {
        $this->assertStaff($actorId);
        if (mb_strlen(trim($reason)) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Provide a no-show reason between 10 and 500 characters.']);
        }

        return DB::transaction(function () use ($bookingId, $actorId, $reason): Booking {
            DB::table('booking_mutexes')->where('id', 1)->lockForUpdate()->first();
            $booking = Booking::query()->lockForUpdate()->findOrFail($bookingId);
            if ($booking->status === 'no_show') {
                return $booking;
            }
            if ($booking->status !== 'confirmed' || $booking->starts_at->greaterThan(CarbonImmutable::now('UTC'))) {
                throw ValidationException::withMessages(['booking' => 'A no-show can only be recorded for a confirmed appointment whose start time has passed.']);
            }

            if ($booking->bundle_entitlement_id !== null) {
                $entitlement = BundleEntitlement::query()->lockForUpdate()->findOrFail($booking->bundle_entitlement_id);
                $this->recordForfeitedCredit($booking, $entitlement, $actorId, 'Authorized staff recorded a customer no-show.', 'booking-no-show-forfeited:'.$booking->public_reference);
            }
            if ($booking->free_session_claim_id !== null) {
                FreeSessionClaim::query()->whereKey($booking->free_session_claim_id)->update(['status' => 'no_show', 'used_at' => now()]);
            }

            $booking->update(['status' => 'no_show', 'no_show_at' => now(), 'no_show_reason' => trim($reason)]);
            BookingAudit::query()->create([
                'booking_id' => $booking->id,
                'bundle_entitlement_id' => $booking->bundle_entitlement_id,
                'actor_id' => $actorId,
                'action' => 'booking.no_show_recorded',
                'reason' => trim($reason),
                'metadata' => ['credit_forfeited' => $booking->bundle_entitlement_id !== null, 'free_introduction' => $booking->bundle_entitlement_id === null, 'accepted_policy_version' => $booking->accepted_policy_version],
            ]);
            $this->notifications->enqueueBookingEvent($booking->refresh(), NotificationEvent::BookingNoShow, ['credit_forfeited' => $booking->bundle_entitlement_id !== null], 'no-show');

            return $booking->refresh();
        }, attempts: 3);
    }

    private function restoreReservedCredit(Booking $booking, BundleEntitlement $entitlement, ?int $actorId, string $reason, string $idempotencyKey): void
    {
        if ($booking->credit_type === 'free') {
            $entitlement->increment('free_credits_remaining');
        } else {
            $entitlement->increment('credits_remaining');
        }
        $balanceAfter = $booking->credit_type === 'free'
            ? $entitlement->refresh()->free_credits_remaining
            : $entitlement->refresh()->credits_remaining;
        CreditTransaction::query()->firstOrCreate(['idempotency_key' => $idempotencyKey], [
            'bundle_entitlement_id' => $entitlement->id,
            'booking_id' => $booking->id,
            'created_by' => $actorId,
            'type' => 'restored',
            'credit_type' => $booking->credit_type ?? 'paid',
            'quantity' => 1,
            'balance_after' => $balanceAfter,
            'reason' => $reason,
        ]);
    }

    private function recordForfeitedCredit(Booking $booking, BundleEntitlement $entitlement, ?int $actorId, string $reason, string $idempotencyKey): void
    {
        $balanceAfter = $booking->credit_type === 'free' ? $entitlement->free_credits_remaining : $entitlement->credits_remaining;
        CreditTransaction::query()->firstOrCreate(['idempotency_key' => $idempotencyKey], [
            'bundle_entitlement_id' => $entitlement->id,
            'booking_id' => $booking->id,
            'created_by' => $actorId,
            'type' => 'forfeited',
            'credit_type' => $booking->credit_type ?? 'paid',
            'quantity' => 0,
            'balance_after' => $balanceAfter,
            'reason' => $reason,
        ]);
    }

    private function assertStaff(int $actorId): void
    {
        $actor = User::query()->findOrFail($actorId);
        if (! $actor->is_staff) {
            throw new AuthorizationException('Only authorized staff can perform this booking action.');
        }
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
