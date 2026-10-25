<?php

use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\BundleDefinition;
use App\Models\BundleEntitlement;
use App\Services\Booking\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 08:00:00', 'Europe/Bucharest'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function addPublicAvailability(CarbonImmutable $day): void
{
    AvailabilityRule::query()->create([
        'weekday' => $day->dayOfWeekIso % 7,
        'starts_at' => '09:00',
        'ends_at' => '11:00',
        'is_active' => true,
    ]);
}

test('public landing page renders in Romanian by default with approved prices and session duration', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Mai multă încredere în engleză.')
        ->assertSee('156')
        ->assertSee('390')
        ->assertSee('780')
        ->assertSee('15 minute pe sesiune')
        ->assertSee('Europe/Bucharest')
        ->assertSee('O singură sesiune introductivă gratuită per persoană');
});

test('visitor can switch the public experience to English without an account', function () {
    $this->from('/')->post('/language', ['locale' => 'en'])->assertRedirect('/');

    $this->get('/')->assertOk()->assertSee('More confidence in English.')->assertSee('MOST POPULAR');
});

test('availability endpoint returns all seven days and only server-bookable times', function () {
    $day = CarbonImmutable::parse('2026-10-27', 'Europe/Bucharest');
    addPublicAvailability($day);

    $this->getJson('/availability?week=2026-10-26')
        ->assertOk()
        ->assertJsonPath('timezone', AvailabilityService::TIMEZONE)
        ->assertJsonCount(7, 'days')
        ->assertJsonPath('days.1.date', '2026-10-27')
        ->assertJsonPath('days.1.slots.0.time', '09:00')
        ->assertJsonPath('days.1.slots.1.time', '09:30');
});

test('public privacy and terms pages are available in both locales', function () {
    $this->get('/privacy')->assertOk()->assertSee('Confidențialitate');
    $this->from('/privacy')->post('/language', ['locale' => 'en'])->assertRedirect('/privacy');
    $this->get('/terms')->assertOk()->assertSee('Terms and cancellation policy');
});

test('free introduction never creates a booking before contact verification is available', function () {
    $day = CarbonImmutable::parse('2026-10-27', 'Europe/Bucharest');
    addPublicAvailability($day);

    $this->from('/book?type=intro')->post('/book', [
        'type' => 'intro', 'starts_at' => '2026-10-27T09:00:00+02:00',
        'name' => 'Test Learner', 'email' => 'learner@example.com', 'phone' => '+40722123456', 'whatsapp_service_consent' => '1',
        'english_level' => 'B1', 'session_goal' => 'Work conversations', 'accepted_policy' => '1',
    ])->assertRedirect('/book?type=intro')->assertSessionHasErrors('verification');

    expect(Booking::query()->count())->toBe(0);
});

test('active bundle customer can book a slot without a learner account and receives a signed confirmation', function () {
    $day = CarbonImmutable::parse('2026-10-27', 'Europe/Bucharest');
    addPublicAvailability($day);
    $definition = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $entitlement = BundleEntitlement::query()->create([
        'bundle_definition_id' => $definition->id,
        'customer_name' => 'Test Learner',
        'customer_email' => 'learner@example.com',
        'customer_phone' => '+40722123456',
        'status' => 'active', 'paid_amount_ron' => 156, 'currency' => 'RON', 'paid_credits' => 4,
        'free_credits_granted' => 0, 'credits_remaining' => 4, 'free_credits_remaining' => 0,
        'activated_at' => now(), 'expires_at' => now()->addMonths(3),
    ]);
    $signedFormUrl = URL::signedRoute('booking.create', ['type' => 'bundle', 'entitlement' => $entitlement->id], now()->addMinutes(30), false);

    $this->withSession(['locale' => 'en'])->get($signedFormUrl)->assertOk()->assertSee('Schedule a session from your bundle');

    $signedSubmitUrl = URL::signedRoute('booking.submit', ['type' => 'bundle', 'entitlement' => $entitlement->id], now()->addMinutes(30), false);
    $this->post($signedSubmitUrl, [
        'type' => 'bundle', 'entitlement' => $entitlement->id, 'starts_at' => '2026-10-27T09:00:00+02:00',
        'name' => 'Test Learner', 'email' => 'learner@example.com', 'phone' => '+40722123456', 'whatsapp_service_consent' => '1',
        'english_level' => 'B1', 'session_goal' => 'Work conversations', 'accepted_policy' => '1',
    ])->assertRedirect();

    expect(Booking::query()->where('status', 'confirmed')->count())->toBe(1)
        ->and($entitlement->fresh()->credits_remaining)->toBe(3);
    $response = $this->get(Booking::query()->firstOrFail()->public_reference ? URL::signedRoute('booking.confirmed', ['reference' => Booking::query()->firstOrFail()->public_reference], null, false) : '/');
    $response->assertOk()->assertSee('Your booking is confirmed.');
});

test('public booking rejects a phone field without enough digits', function () {
    $day = CarbonImmutable::parse('2026-10-27', 'Europe/Bucharest');
    addPublicAvailability($day);

    $this->from('/book?type=intro')->post('/book', [
        'type' => 'intro', 'starts_at' => '2026-10-27T09:00:00+02:00',
        'name' => 'Test Learner', 'email' => 'different@example.com', 'phone' => '-------',
        'english_level' => 'B1', 'session_goal' => 'Work conversations', 'accepted_policy' => '1',
    ])->assertRedirect('/book?type=intro')->assertSessionHasErrors('phone');

    expect(Booking::query()->count())->toBe(0);
});

test('bundle booking form refuses unsigned or expired entitlement links', function () {
    $definition = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $entitlement = BundleEntitlement::query()->create([
        'bundle_definition_id' => $definition->id, 'customer_name' => 'Test Learner', 'customer_email' => 'learner@example.com',
        'customer_phone' => '+40722123456', 'status' => 'active', 'paid_amount_ron' => 156, 'currency' => 'RON',
        'paid_credits' => 4, 'free_credits_granted' => 0, 'credits_remaining' => 4, 'free_credits_remaining' => 0,
        'activated_at' => now(), 'expires_at' => now()->addMonths(3),
    ]);

    $this->get('/book?type=bundle&entitlement='.$entitlement->id)->assertForbidden();
    $expired = URL::signedRoute('booking.create', ['type' => 'bundle', 'entitlement' => $entitlement->id], now()->subMinute(), false);
    $this->get($expired)->assertForbidden();
});

test('bundle purchase page discloses expiry and cancellation terms before checkout is available', function () {
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();

    $this->get(route('purchase', $bundle))
        ->assertOk()
        ->assertSee('156 RON')
        ->assertSee('trei luni calendaristice')
        ->assertSee('O singură sesiune introductivă gratuită per persoană')
        ->assertSee('Programările trebuie să înceapă înainte de expirarea pachetului')
        ->assertSee(route('terms'), false)
        ->assertSee('Plata online nu este încă activată');
});
