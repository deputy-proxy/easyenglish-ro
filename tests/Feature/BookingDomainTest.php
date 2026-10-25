<?php

use App\Enums\PaymentStatus;
use App\Models\AvailabilityException;
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
use App\Services\Booking\BundleEntitlementService;
use App\Services\Booking\PaymentStateService;
use Carbon\CarbonImmutable;
use Filament\Panel;
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
