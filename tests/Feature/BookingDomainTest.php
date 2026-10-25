<?php

use App\Enums\PaymentStatus;
use App\Models\AvailabilityException;
use App\Models\Booking;
use App\Models\NotificationDelivery;
use App\Models\AvailabilityRule;
use App\Models\BookingAudit;
use App\Models\BundleDefinition;
use App\Models\BundleEntitlement;
use App\Models\CreditTransaction;
use App\Models\FreeSessionClaim;
use App\Models\Payment;
use App\Models\User;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingService;
use App\Services\Booking\CancellationPolicyService;
use App\Services\Booking\BundleEntitlementService;
use App\Services\Booking\PaymentStateService;
use Carbon\CarbonImmutable;
use Filament\Panel;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function addAvailabilityWindow(CarbonImmutable $day, string $start = '09:00', string $end = '12:00'): AvailabilityRule
{
    return AvailabilityRule::query()->create([
        'weekday' => $day->dayOfWeekIso % 7,
        'starts_at' => $start,
        'ends_at' => $end,
        'is_active' => true,
    ]);
}

function makeBundleEntitlement(array $overrides = []): BundleEntitlement
{
    $definition = BundleDefinition::query()->firstOrCreate(['name' => 'Test bundle'], [
        'price_ron' => 156, 'paid_credits' => 4, 'included_free_credits' => 1, 'is_active' => true,
    ]);

    return BundleEntitlement::query()->create(array_merge([
        'bundle_definition_id' => $definition->id,
        'customer_name' => 'Test Learner',
        'customer_email' => 'learner@example.com',
        'customer_phone' => '+40722123456',
        'status' => 'active',
        'paid_amount_ron' => 156,
        'currency' => 'RON',
        'paid_credits' => 4,
        'free_credits_granted' => 0,
        'credits_remaining' => 4,
        'free_credits_remaining' => 0,
        'activated_at' => now(),
        'expires_at' => now()->addMonths(3),
    ], $overrides));
}

function bookingCustomer(array $overrides = []): array
{
    return array_merge([
        'name' => 'Test Learner', 'email' => 'learner@example.com', 'phone' => '+40722123456',
        'english_level' => 'B1', 'session_goal' => 'Practise speaking', 'notes' => null,
        'accepted_policy_version' => '2026-10-25',
    ], $overrides);
}

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 08:00:00', 'Europe/Bucharest'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('weekly availability returns 15-minute sessions with a 15-minute buffer in Bucharest time', function () {
    $day = CarbonImmutable::parse('2026-10-27 00:00:00', 'Europe/Bucharest');
    addAvailabilityWindow($day, '09:00', '11:00');

    $slots = app(AvailabilityService::class)->slotsForDate('2026-10-27');

    expect($slots)->toHaveCount(4)
        ->and($slots->map(fn (array $slot) => $slot['starts_at']->format('H:i'))->all())->toBe(['09:00', '09:30', '10:00', '10:30'])
        ->and($slots->first()['ends_at']->diffInMinutes($slots->first()['starts_at']))->toBe(-15.0);
});

test('blocked periods remove slots and active weekly windows cannot overlap', function () {
    $day = CarbonImmutable::parse('2026-10-27', 'Europe/Bucharest');
    addAvailabilityWindow($day, '09:00', '11:00');
    AvailabilityException::query()->create([
        'kind' => 'blocked', 'starts_at' => CarbonImmutable::parse('2026-10-27 09:05', 'Europe/Bucharest')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-10-27 09:10', 'Europe/Bucharest')->utc(), 'reason' => 'Teacher unavailable',
    ]);

    expect(app(AvailabilityService::class)->slotsForDate('2026-10-27')->map(fn (array $slot) => $slot['starts_at']->format('H:i'))->all())->toBe(['09:30', '10:00', '10:30']);

    expect(fn () => addAvailabilityWindow($day, '10:00', '12:00'))->toThrow(ValidationException::class);
    expect(fn () => addAvailabilityWindow($day, '23:00', '01:00'))
        ->not->toThrow(ValidationException::class);
});

test('booking holds reserve credits and prevent duplicate overlapping reservations', function () {
    $day = CarbonImmutable::parse('2026-10-27', 'Europe/Bucharest');
    addAvailabilityWindow($day);
    $entitlement = makeBundleEntitlement();
    $service = app(BookingService::class);
    $start = CarbonImmutable::parse('2026-10-27 09:00:00', 'Europe/Bucharest');

    $booking = $service->holdSlot(bookingCustomer(), $start, $entitlement->id);

    expect($booking->status)->toBe('pending')
        ->and($booking->duration_minutes)->toBe(15)
        ->and($booking->hold_expires_at->diffInMinutes(now()))->toBe(-5.0)
        ->and($entitlement->fresh()->credits_remaining)->toBe(3)
        ->and(CreditTransaction::query()->where('booking_id', $booking->id)->where('type', 'reserved')->exists())->toBeTrue();

    expect(fn () => $service->holdSlot(bookingCustomer(), $start, $entitlement->id))
        ->toThrow(ValidationException::class);
});

test('expired holds restore reserved paid credits and free-session claims', function () {
    $day = CarbonImmutable::parse('2026-10-27', 'Europe/Bucharest');
    addAvailabilityWindow($day);
    $entitlement = makeBundleEntitlement(['credits_remaining' => 1]);
    $service = app(BookingService::class);
    $booking = $service->holdSlot(bookingCustomer(), CarbonImmutable::parse('2026-10-27 09:00', 'Europe/Bucharest'), $entitlement->id);
    $booking->update(['hold_expires_at' => now()->subSecond()]);

    expect($service->expireHolds())->toBe(1)
        ->and($booking->fresh()->status)->toBe('expired')
        ->and($entitlement->fresh()->credits_remaining)->toBe(1)
        ->and(CreditTransaction::query()->where('booking_id', $booking->id)->where('type', 'restored')->exists())->toBeTrue();
});

test('free introduction requires verified contact details and only one claim per email or phone', function () {
    $day = CarbonImmutable::parse('2026-10-27', 'Europe/Bucharest');
    addAvailabilityWindow($day);
    $service = app(BookingService::class);
    $start = CarbonImmutable::parse('2026-10-27 09:00', 'Europe/Bucharest');

    expect(fn () => $service->holdSlot(bookingCustomer(), $start, null, true, false, true))->toThrow(ValidationException::class);

    $booking = $service->holdSlot(bookingCustomer(), $start, null, true, true, true);
    expect($booking->free_session_claim_id)->not->toBeNull();
    expect(fn () => $service->holdSlot(bookingCustomer(['email' => 'another@example.com']), $start->addMinutes(30), null, true, true, true))->toThrow(ValidationException::class);
    expect(fn () => $service->holdSlot(bookingCustomer(['email' => 'third@example.com', 'phone' => '0722 123 456']), $start->addMinutes(30), null, true, true, true))->toThrow(ValidationException::class);
    expect(fn () => $service->holdSlot(bookingCustomer(['email' => 'invalid-phone@example.com', 'phone' => '-------']), $start->addMinutes(30), null, true, true, true))->toThrow(ValidationException::class);
});

test('bundle activation grants credits for three calendar months and respects the shared free offer', function () {
    $definition = BundleDefinition::query()->create(['name' => 'Activation test', 'price_ron' => 390, 'paid_credits' => 10, 'included_free_credits' => 1, 'is_active' => true]);
    FreeSessionClaim::query()->create(['customer_email' => 'buyer@example.com', 'customer_phone' => '+40722111111', 'status' => 'used', 'verified_at' => now(), 'used_at' => now()]);
    $entitlement = BundleEntitlement::query()->create([
        'bundle_definition_id' => $definition->id, 'customer_name' => 'Buyer', 'customer_email' => 'buyer@example.com',
        'customer_phone' => '+40722111111', 'status' => 'pending_payment', 'paid_amount_ron' => 390, 'currency' => 'RON',
        'paid_credits' => 10, 'free_credits_granted' => 0, 'credits_remaining' => 0, 'free_credits_remaining' => 0,
    ]);

    $activated = app(BundleEntitlementService::class)->activateAfterVerifiedPayment($entitlement->id);

    expect($activated->status)->toBe('active')
        ->and($activated->credits_remaining)->toBe(10)
        ->and($activated->free_credits_remaining)->toBe(0)
        ->and($activated->expires_at->setTimezone('Europe/Bucharest')->format('Y-m-d H:i'))->toBe('2027-01-26 08:00')
        ->and($activated->transactions()->count())->toBe(1);
});

test('only explicitly provisioned staff can access the Filament panel', function () {
    $panel = app(Panel::class);
    $staff = new User;
    $staff->is_staff = true;

    expect((new User)->canAccessPanel($panel))->toBeFalse()
        ->and($staff->canAccessPanel($panel))->toBeTrue();
});

test('overnight availability windows can contain a session ending at midnight', function () {
    $day = CarbonImmutable::parse('2026-10-27', 'Europe/Bucharest');
    addAvailabilityWindow($day, '23:45', '00:15');

    $slots = app(AvailabilityService::class)->slotsForDate('2026-10-27');

    expect($slots)->toHaveCount(1)
        ->and($slots->first()['starts_at']->format('H:i'))->toBe('23:45')
        ->and($slots->first()['ends_at']->format('Y-m-d H:i'))->toBe('2026-10-28 00:00');
});

test('confirming a held booking is idempotent and cannot confirm after expiry', function () {
    $day = CarbonImmutable::parse('2026-10-27', 'Europe/Bucharest');
    addAvailabilityWindow($day);
    $entitlement = makeBundleEntitlement(['credits_remaining' => 2]);
    $service = app(BookingService::class);
    $booking = $service->holdSlot(bookingCustomer(), CarbonImmutable::parse('2026-10-27 09:00', 'Europe/Bucharest'), $entitlement->id);

    expect($service->confirm($booking->id)->status)->toBe('confirmed')
        ->and($service->confirm($booking->id)->status)->toBe('confirmed')
        ->and(CreditTransaction::query()->where('booking_id', $booking->id)->where('type', 'consumed')->count())->toBe(1);

    $another = $service->holdSlot(bookingCustomer(), CarbonImmutable::parse('2026-10-27 09:30', 'Europe/Bucharest'), $entitlement->id);
    $another->update(['hold_expires_at' => now()->subSecond()]);
    expect(fn () => $service->confirm($another->id))->toThrow(ValidationException::class);
});

test('staff can open Filament booking administration while ordinary users are denied', function () {
    $ordinary = User::factory()->create();
    $this->actingAs($ordinary)->get('/admin/bookings')->assertForbidden();

    $staff = User::factory()->create();
    $staff->forceFill(['is_staff' => true])->save();
    $this->actingAs($staff)->get('/admin/bookings')->assertOk();
    $this->get('/admin/availability-rules')->assertOk();
    $this->get('/admin/availability-exceptions')->assertOk();
    $this->get('/admin/bundle-entitlements')->assertOk();
    $this->get('/admin/credit-transactions')->assertOk();
    $this->get('/admin/booking-audits')->assertOk();
});

test('included free credit is shared across bundles and restored when its slot hold expires', function () {
    $day = CarbonImmutable::parse('2026-10-27', 'Europe/Bucharest');
    addAvailabilityWindow($day);
    $definition = BundleDefinition::query()->create(['name' => 'Shared free test', 'price_ron' => 156, 'paid_credits' => 4, 'included_free_credits' => 1, 'is_active' => true]);
    $first = BundleEntitlement::query()->create([
        'bundle_definition_id' => $definition->id, 'customer_name' => 'Shared Buyer', 'customer_email' => 'shared@example.com',
        'customer_phone' => '+40722333333', 'customer_email_verified_at' => now(), 'customer_phone_verified_at' => now(), 'status' => 'pending_payment', 'paid_amount_ron' => 156, 'currency' => 'RON',
        'paid_credits' => 4, 'free_credits_granted' => 0, 'credits_remaining' => 0, 'free_credits_remaining' => 0,
    ]);
    $second = BundleEntitlement::query()->create([
        'bundle_definition_id' => $definition->id, 'customer_name' => 'Shared Buyer', 'customer_email' => 'shared@example.com',
        'customer_phone' => '+40722333333', 'customer_email_verified_at' => now(), 'customer_phone_verified_at' => now(), 'status' => 'pending_payment', 'paid_amount_ron' => 156, 'currency' => 'RON',
        'paid_credits' => 4, 'free_credits_granted' => 0, 'credits_remaining' => 0, 'free_credits_remaining' => 0,
    ]);
    $activation = app(BundleEntitlementService::class);
    $first = $activation->activateAfterVerifiedPayment($first->id);
    $second = $activation->activateAfterVerifiedPayment($second->id);

    expect($first->free_credits_remaining)->toBe(1)
        ->and($second->free_credits_remaining)->toBe(0);

    $booking = app(BookingService::class)->holdSlot(bookingCustomer(['email' => 'shared@example.com', 'phone' => '+40722333333']), CarbonImmutable::parse('2026-10-27 09:00', 'Europe/Bucharest'), $first->id);
    expect($booking->credit_type)->toBe('free')
        ->and($first->fresh()->free_credits_remaining)->toBe(0);

    $booking->update(['hold_expires_at' => now()->subSecond()]);
    app(BookingService::class)->expireHolds();

    expect($first->fresh()->free_credits_remaining)->toBe(1)
        ->and(FreeSessionClaim::query()->where('bundle_entitlement_id', $first->id)->where('status', 'bundle_credit_granted')->whereNull('booking_id')->exists())->toBeTrue()
        ->and(fn () => app(BookingService::class)->holdSlot(bookingCustomer(['email' => 'shared@example.com', 'phone' => '+40722333333']), CarbonImmutable::parse('2026-10-27 09:30', 'Europe/Bucharest'), null, true, true, true))->toThrow(ValidationException::class);
});

test('payment state transitions are explicit and successful payment activates a bundle once', function () {
    $definition = BundleDefinition::query()->create(['name' => 'Payment state test', 'price_ron' => 156, 'paid_credits' => 4, 'included_free_credits' => 1, 'is_active' => true]);
    $entitlement = BundleEntitlement::query()->create([
        'bundle_definition_id' => $definition->id, 'customer_name' => 'Paying Buyer', 'customer_email' => 'paying@example.com',
        'customer_phone' => '+40722444444', 'status' => 'pending_payment', 'paid_amount_ron' => 156, 'currency' => 'RON',
        'paid_credits' => 4, 'free_credits_granted' => 0, 'credits_remaining' => 0, 'free_credits_remaining' => 0,
    ]);
    $payment = Payment::query()->create([
        'bundle_entitlement_id' => $entitlement->id, 'provider' => 'stripe', 'amount_ron' => 156, 'currency' => 'RON', 'status' => 'pending',
    ]);
    $service = app(PaymentStateService::class);

    expect($service->transitionAfterVerifiedProviderEvent($payment, PaymentStatus::Succeeded)->status)->toBe('succeeded')
        ->and($entitlement->fresh()->status)->toBe('active')
        ->and($entitlement->fresh()->credits_remaining)->toBe(4)
        ->and($service->transitionAfterVerifiedProviderEvent($payment, PaymentStatus::Succeeded)->status)->toBe('succeeded');

    expect(fn () => $service->transitionAfterVerifiedProviderEvent($payment, PaymentStatus::Failed))->toThrow(ValidationException::class);
    expect(fn () => $service->transitionAfterVerifiedProviderEvent($payment, PaymentStatus::Refunded))->toThrow(ValidationException::class);
});

test('overnight recurring availability and date overrides continue into the following day', function () {
    $previousDay = CarbonImmutable::parse('2026-10-26', 'Europe/Bucharest');
    addAvailabilityWindow($previousDay, '23:15', '01:15');

    $nextDaySlots = app(AvailabilityService::class)->slotsForDate('2026-10-27');
    expect($nextDaySlots->map(fn (array $slot) => $slot['starts_at']->format('Y-m-d H:i'))->all())->toContain('2026-10-27 00:15');

    AvailabilityException::query()->create([
        'kind' => 'availability_override',
        'starts_at' => CarbonImmutable::parse('2026-10-27 23:15', 'Europe/Bucharest')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-10-28 01:15', 'Europe/Bucharest')->utc(),
        'reason' => 'Temporary late availability',
    ]);
    expect(app(AvailabilityService::class)->slotsForDate('2026-10-28')->map(fn (array $slot) => $slot['starts_at']->format('Y-m-d H:i'))->all())->toContain('2026-10-28 00:15');
});

test('availability remains valid across the spring daylight-saving jump', function () {
    $day = CarbonImmutable::parse('2027-03-28', 'Europe/Bucharest');
    addAvailabilityWindow($day, '02:00', '04:00');

    $slots = app(AvailabilityService::class)->slotsForDate('2027-03-28');

    expect($slots->map(fn (array $slot) => $slot['starts_at']->format('H:i'))->all())->toBe(['02:00', '02:30'])
        ->and($slots->pluck('starts_at')->map(fn (CarbonImmutable $start) => $start->utc()->timestamp)->unique())->toHaveCount(2);
});

test('bundle included free credit waits for both verified contact channels', function () {
    $definition = BundleDefinition::query()->create(['name' => 'Verification gate test', 'price_ron' => 156, 'paid_credits' => 4, 'included_free_credits' => 1, 'is_active' => true]);
    $entitlement = BundleEntitlement::query()->create([
        'bundle_definition_id' => $definition->id, 'customer_name' => 'Unverified Buyer', 'customer_email' => 'unverified@example.com',
        'customer_phone' => '+40722555555', 'status' => 'pending_payment', 'paid_amount_ron' => 156, 'currency' => 'RON',
        'paid_credits' => 4, 'free_credits_granted' => 0, 'credits_remaining' => 0, 'free_credits_remaining' => 0,
    ]);
    $service = app(BundleEntitlementService::class);
    $activated = $service->activateAfterVerifiedPayment($entitlement->id);
    expect($activated->status)->toBe('active')->and($activated->free_credits_remaining)->toBe(0);

    $activated->update(['customer_email_verified_at' => now()]);
    expect($service->grantIncludedFreeCreditIfEligible($activated->id)->free_credits_remaining)->toBe(0);
    $activated->update(['customer_phone_verified_at' => now()]);
    expect($service->grantIncludedFreeCreditIfEligible($activated->id)->free_credits_remaining)->toBe(1)
        ->and($service->grantIncludedFreeCreditIfEligible($activated->id)->free_credits_remaining)->toBe(1);
});

test('staff credit adjustments are auditable and cannot create a negative balance', function () {
    $entitlement = makeBundleEntitlement(['credits_remaining' => 2]);
    $staff = User::factory()->create();
    $service = app(BundleEntitlementService::class);

    expect($service->adjustPaidCredits($entitlement->id, 3, $staff->id, 'Correct credit count after review')->credits_remaining)->toBe(5)
        ->and(CreditTransaction::query()->where('bundle_entitlement_id', $entitlement->id)->where('type', 'adjusted')->value('quantity'))->toBe(3)
        ->and(BookingAudit::query()->where('bundle_entitlement_id', $entitlement->id)->where('actor_id', $staff->id)->where('action', 'bundle.paid_credits_adjusted')->exists())->toBeTrue();

    expect(fn () => $service->adjustPaidCredits($entitlement->id, -6, $staff->id, 'Attempt to overdraw balance'))->toThrow(ValidationException::class);
});


function makeLifecycleConfirmedBooking(?BundleEntitlement $entitlement, CarbonImmutable $startsAt, ?string $creditType = 'paid', ?int $freeClaimId = null, array $overrides = []): Booking
{
    return Booking::query()->create(array_merge([
        'public_reference' => (string) Str::uuid(),
        'bundle_entitlement_id' => $entitlement?->id,
        'free_session_claim_id' => $freeClaimId,
        'customer_name' => $entitlement?->customer_name ?? 'Lifecycle Learner',
        'customer_email' => $entitlement?->customer_email ?? 'lifecycle@example.com',
        'customer_phone' => $entitlement?->customer_phone ?? '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Practise speaking',
        'notes' => null,
        'accepted_policy_version' => '2026-10-25',
        'starts_at' => $startsAt->utc(),
        'ends_at' => $startsAt->utc()->addMinutes(15),
        'duration_minutes' => 15,
        'status' => 'confirmed',
        'credit_type' => $creditType,
        'locale' => 'en',
        'hold_expires_at' => null,
        'confirmed_at' => now(),
    ], $overrides));
}

test('cancellation policy has exact non-overlapping 24-hour and 12-hour boundaries', function () {
    $policy = app(CancellationPolicyService::class);
    $now = CarbonImmutable::now('UTC');
    $at24Hours = new Booking(['starts_at' => $now->addHours(24), 'status' => 'confirmed', 'reschedule_count' => 0]);
    $at12Hours = new Booking(['starts_at' => $now->addHours(12), 'status' => 'confirmed', 'reschedule_count' => 0]);
    $under12Hours = new Booking(['starts_at' => $now->addHours(12)->subSecond(), 'status' => 'confirmed', 'reschedule_count' => 0]);

    expect($policy->customerCancellationOutcome($at24Hours, $now))->toBe('restore')
        ->and($policy->customerActions($at24Hours, $now)['can_reschedule'])->toBeFalse()
        ->and($policy->customerCancellationOutcome($at12Hours, $now))->toBe('forfeit')
        ->and($policy->customerActions($at12Hours, $now)['can_reschedule'])->toBeTrue()
        ->and($policy->customerActions($under12Hours, $now)['can_reschedule'])->toBeFalse();
});

test('customer cancellation at exactly 24 hours restores a reserved credit once and is idempotent', function () {
    $day = CarbonImmutable::parse('2026-10-27', 'Europe/Bucharest');
    addAvailabilityWindow($day, '08:00', '09:00');
    $entitlement = makeBundleEntitlement(['credits_remaining' => 3]);
    $booking = app(BookingService::class)->holdSlot(bookingCustomer(), CarbonImmutable::parse('2026-10-27 08:00', 'Europe/Bucharest'), $entitlement->id);
    app(BookingService::class)->confirm($booking->id);

    $service = app(BookingService::class);
    expect($service->cancelByCustomer($booking->id)->status)->toBe('cancelled')
        ->and($service->cancelByCustomer($booking->id)->status)->toBe('cancelled')
        ->and($entitlement->fresh()->credits_remaining)->toBe(4)
        ->and(CreditTransaction::query()->where('booking_id', $booking->id)->where('type', 'restored')->count())->toBe(1)
        ->and(BookingAudit::query()->where('booking_id', $booking->id)->where('action', 'booking.customer_cancelled')->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('booking_id', $booking->id)->where('event', 'booking_cancelled')->count())->toBe(1);
});

test('customer cancellation under 24 hours forfeits the reserved credit without restoring the balance', function () {
    $day = CarbonImmutable::parse('2026-10-27', 'Europe/Bucharest');
    addAvailabilityWindow($day, '07:00', '08:00');
    $entitlement = makeBundleEntitlement(['credits_remaining' => 3]);
    $booking = app(BookingService::class)->holdSlot(bookingCustomer(), CarbonImmutable::parse('2026-10-27 07:00', 'Europe/Bucharest'), $entitlement->id);
    app(BookingService::class)->confirm($booking->id);

    app(BookingService::class)->cancelByCustomer($booking->id, 'Plans changed.');

    expect($booking->fresh()->status)->toBe('cancelled')
        ->and($entitlement->fresh()->credits_remaining)->toBe(3)
        ->and(CreditTransaction::query()->where('booking_id', $booking->id)->where('type', 'forfeited')->count())->toBe(1);
});

test('customer can reschedule once at the exact 12-hour boundary without reserving another credit', function () {
    $today = CarbonImmutable::parse('2026-10-26', 'Europe/Bucharest');
    $tomorrow = $today->addDay();
    addAvailabilityWindow($today, '20:00', '21:00');
    addAvailabilityWindow($tomorrow, '09:00', '11:00');
    $entitlement = makeBundleEntitlement(['credits_remaining' => 3]);
    $booking = app(BookingService::class)->holdSlot(bookingCustomer(), $today->setTime(20, 0), $entitlement->id);
    app(BookingService::class)->confirm($booking->id);

    $newStart = $tomorrow->setTime(9, 0);
    $service = app(BookingService::class);
    $rescheduled = $service->rescheduleByCustomer($booking->id, $newStart);
    $duplicate = $service->rescheduleByCustomer($booking->id, $newStart);

    expect($rescheduled->starts_at->utc()->getTimestamp())->toBe($newStart->utc()->getTimestamp())
        ->and($duplicate->reschedule_count)->toBe(1)
        ->and($entitlement->fresh()->credits_remaining)->toBe(3)
        ->and(BookingAudit::query()->where('booking_id', $booking->id)->where('action', 'booking.customer_rescheduled')->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('booking_id', $booking->id)->where('event', 'booking_rescheduled')->count())->toBe(1);
});

test('teacher cancellation restores credits and extends near-expiry bundle validity', function () {
    $day = CarbonImmutable::parse('2026-10-27', 'Europe/Bucharest');
    addAvailabilityWindow($day);
    $entitlement = makeBundleEntitlement(['credits_remaining' => 3, 'expires_at' => now()->addDays(3)]);
    $booking = app(BookingService::class)->holdSlot(bookingCustomer(), CarbonImmutable::parse('2026-10-27 09:00', 'Europe/Bucharest'), $entitlement->id);
    app(BookingService::class)->confirm($booking->id);
    $staff = User::factory()->create(['is_staff' => true]);

    $cancelled = app(BookingService::class)->cancelByTeacher($booking->id, $staff->id, 'Teacher is unexpectedly unavailable.');

    expect($cancelled->status)->toBe('cancelled')
        ->and($cancelled->cancelled_by)->toBe('teacher')
        ->and($entitlement->fresh()->credits_remaining)->toBe(4)
        ->and($entitlement->fresh()->expires_at->greaterThanOrEqualTo(now()->addDays(14)))->toBeTrue()
        ->and(BookingAudit::query()->where('booking_id', $booking->id)->where('action', 'booking.teacher_cancelled')->exists())->toBeTrue()
        ->and(NotificationDelivery::query()->where('booking_id', $booking->id)->where('event', 'teacher_cancellation')->exists())->toBeTrue();
});

test('teacher cancellation reuses the existing free-introduction claim instead of granting a second offer', function () {
    $day = CarbonImmutable::parse('2026-10-27', 'Europe/Bucharest');
    addAvailabilityWindow($day, '09:00', '10:00');
    $service = app(BookingService::class);
    $booking = $service->holdSlot(bookingCustomer(), CarbonImmutable::parse('2026-10-27 09:00', 'Europe/Bucharest'), null, true, true, true);
    $service->confirm($booking->id);
    $claim = FreeSessionClaim::query()->findOrFail($booking->free_session_claim_id);
    $staff = User::factory()->create(['is_staff' => true]);

    $service->cancelByTeacher($booking->id, $staff->id, 'Teacher is unexpectedly unavailable.');
    $rebooked = $service->holdSlot(bookingCustomer(), CarbonImmutable::parse('2026-10-27 09:30', 'Europe/Bucharest'), null, true, true, true);

    expect($claim->fresh()->status)->toBe('reserved')
        ->and($claim->fresh()->booking_id)->toBe($rebooked->id)
        ->and(FreeSessionClaim::query()->where('customer_email', 'learner@example.com')->count())->toBe(1);
});

test('staff no-show recording is explicit, forfeits the reserved credit, and is idempotent', function () {
    $entitlement = makeBundleEntitlement(['credits_remaining' => 3]);
    $booking = makeLifecycleConfirmedBooking($entitlement, CarbonImmutable::now('UTC')->subHour(), 'paid');
    $staff = User::factory()->create(['is_staff' => true]);

    $service = app(BookingService::class);
    expect($service->markNoShow($booking->id, $staff->id, 'Customer did not join the session.')->status)->toBe('no_show')
        ->and($service->markNoShow($booking->id, $staff->id, 'Customer did not join the session.')->status)->toBe('no_show')
        ->and($entitlement->fresh()->credits_remaining)->toBe(3)
        ->and(CreditTransaction::query()->where('booking_id', $booking->id)->where('type', 'forfeited')->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('booking_id', $booking->id)->where('event', 'booking_no_show')->count())->toBe(1);
});

test('signed customer cancellation endpoint cannot be used without a valid signature', function () {
    $booking = makeLifecycleConfirmedBooking(null, CarbonImmutable::now('UTC')->addDay(), null);

    $this->post('/booking/'.$booking->public_reference.'/cancel')->assertForbidden();
    $signedUrl = URL::signedRoute('booking.cancel', ['reference' => $booking->public_reference], $booking->starts_at->addDay());
    $this->post($signedUrl, ['reason' => 'Cannot attend the session.'])->assertRedirect();

    expect($booking->fresh()->status)->toBe('cancelled');
});
