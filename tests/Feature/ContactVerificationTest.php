<?php

use App\Contracts\Notifications\WhatsAppGateway;
use App\Contracts\Payments\StripeGateway;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\BookingCallEvent;
use App\Models\BundleDefinition;
use App\Models\ContactVerificationChallenge;
use App\Models\FreeSessionClaim;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\Booking\AvailabilityService;
use App\Services\Payments\StripeCheckoutService;
use App\Services\Verification\ContactVerificationService;
use App\Services\Verification\FreeSessionEligibilityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\Support\FakeStripeGateway;
use Tests\Support\FakeWhatsAppGateway;

beforeEach(function (): void {
    config([
        'services.whatsapp.enabled' => true,
        'services.whatsapp.access_token' => 'test-token',
        'services.whatsapp.phone_number_id' => 'test-phone-id',
        'services.whatsapp.templates.contact_verification' => 'easyenglish_activation',
        'mail.default' => 'array',
    ]);
    $gateway = new FakeWhatsAppGateway;
    app()->instance(WhatsAppGateway::class, $gateway);
    app()->instance(FakeWhatsAppGateway::class, $gateway);
});

function addVerificationAvailability(Carbon $day): void
{
    AvailabilityRule::query()->create([
        'weekday' => $day->dayOfWeekIso % 7,
        'starts_at' => '09:00',
        'ends_at' => '11:00',
        'is_active' => true,
    ]);
}

function makeVerificationChallenge(array $overrides = []): ContactVerificationChallenge
{
    return ContactVerificationChallenge::query()->create(array_merge([
        'id' => (string) Str::uuid(),
        'customer_email' => 'learner@example.com',
        'customer_phone' => '+40722123456',
        'locale' => 'en',
        'email_token_hash' => hash('sha256', 'email-token'),
        'phone_code_hash' => hash_hmac('sha256', '123456', (string) config('app.key')),
        'expires_at' => now()->addMinutes(30),
    ], $overrides));
}

test('email-only mode sends verification email without requiring WhatsApp configuration', function () {
    config([
        'services.contact_verification.mode' => 'email_only',
        'services.whatsapp.enabled' => false,
        'services.whatsapp.access_token' => null,
        'services.whatsapp.phone_number_id' => null,
    ]);

    $challenge = app(ContactVerificationService::class)->start('Email-only@example.com', '+40722123456', 'en');

    expect($challenge->customer_email)->toBe('email-only@example.com')
        ->and($challenge->email_sent_at)->not->toBeNull()
        ->and($challenge->phone_sent_at)->toBeNull()
        ->and(app(FakeWhatsAppGateway::class)->sent)->toBeEmpty();

    $challenge->update(['email_verified_at' => now()]);
    expect($challenge->fresh()->isComplete())->toBeTrue();
});

test('verification start stores hashes and sends a WhatsApp code without logging secrets', function () {
    $challenge = app(ContactVerificationService::class)->start('Learner@example.com', '+40722123456', 'en');
    $gateway = app(FakeWhatsAppGateway::class);
    $code = $gateway->sent[0]['components'][0]['parameters'][0]['text'];

    expect($challenge->customer_email)->toBe('learner@example.com')
        ->and($challenge->customer_phone)->toBe('+40722123456')
        ->and($challenge->email_token_hash)->not->toBe('')
        ->and($challenge->email_code_hash)->not->toBeNull()
        ->and($challenge->phone_code_hash)->not->toBe($code)
        ->and($code)->toMatch('/^[0-9]{6}$/')
        ->and($gateway->sent)->toHaveCount(1)
        ->and($gateway->sent[0]['template'])->toBe('easyenglish_activation')
        ->and($gateway->sent[0]['language'])->toBe('en')
        ->and($gateway->sent[0]['components'][0])->toBe([
            'type' => 'body',
            'parameters' => [['type' => 'text', 'text' => $code]],
        ])
        ->and($gateway->sent[0]['components'][1])->toBe([
            'type' => 'button',
            'sub_type' => 'url',
            'index' => '0',
            'parameters' => [['type' => 'text', 'text' => $code]],
        ]);
});

test('public verification form starts the email and WhatsApp steps', function () {
    $this->actingAs(User::factory()->create(['email' => 'new-learner@example.com']));
    $this->from('/book?type=intro')->post(route('verification.start'), [
        'return_to' => 'booking',
        'type' => 'intro',
        'name' => 'New Learner',
        'email' => 'new-learner@example.com',
        'phone' => '+40722987654',
        'starts_at' => now()->addDays(2)->toIso8601String(),
        'english_level' => 'B1',
        'session_goal' => 'Work conversations',
        'accepted_policy' => '1',
        'whatsapp_service_consent' => '1',
    ])->assertRedirect('/book?type=intro')->assertSessionHas('status', __('public.verification_sent'));

    $challenge = ContactVerificationChallenge::query()->where('customer_email', 'new-learner@example.com')->firstOrFail();
    expect(session('contact_verification_id'))->toBe($challenge->id)
        ->and(app(FakeWhatsAppGateway::class)->sent)->toHaveCount(1)
        ->and(WhatsAppContact::query()->where('phone', '+40722987654')->value('consent_source'))->toBe('contact_verification');
});

test('bundle checkout can start verification and preserves the bundle destination', function () {
    $this->actingAs(User::factory()->create(['email' => 'bundle-verification@example.com']));
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $this->from(route('purchase', $bundle))->post(route('verification.start'), [
        'return_to' => 'purchase',
        'bundle_id' => $bundle->id,
        'email' => 'bundle-verification@example.com',
        'phone' => '+40722987655',
        'whatsapp_service_consent' => '1',
    ])->assertRedirect(route('purchase', $bundle));

    $challenge = ContactVerificationChallenge::query()->where('customer_email', 'bundle-verification@example.com')->firstOrFail();
    expect($challenge->return_to)->toBe('purchase')
        ->and($challenge->return_bundle_id)->toBe($bundle->id);
});

test('verification requests are rate-limited per normalized contact', function () {
    $key = 'contact-verification:email:'.hash('sha256', 'limited@example.com');
    for ($attempt = 0; $attempt < 5; $attempt++) {
        RateLimiter::hit($key, 3600);
    }

    expect(fn () => app(ContactVerificationService::class)->start('limited@example.com', '+40722987656', 'en'))
        ->toThrow(ValidationException::class);
    expect(ContactVerificationChallenge::query()->count())->toBe(0);
});

test('provider delivery failure invalidates a partially delivered challenge', function () {
    $gateway = Mockery::mock(WhatsAppGateway::class);
    $gateway->shouldReceive('sendTemplate')->once()->andThrow(new RuntimeException('fake provider failure'));
    app()->instance(WhatsAppGateway::class, $gateway);

    expect(fn () => app(ContactVerificationService::class)->start('partial@example.com', '+40722987657', 'en'))
        ->toThrow(RuntimeException::class);
    $challenge = ContactVerificationChallenge::query()->where('customer_email', 'partial@example.com')->firstOrFail();
    expect($challenge->isUsable())->toBeFalse();
});

test('verification prune command removes expired challenges after the retention window', function () {
    $old = makeVerificationChallenge(['expires_at' => now()->subDays(2)]);
    $recent = makeVerificationChallenge([
        'customer_email' => 'recent@example.com',
        'customer_phone' => '+40722987658',
        'expires_at' => now()->subHours(2),
    ]);

    $this->artisan('verification:prune')->assertSuccessful();

    expect(ContactVerificationChallenge::query()->find($old->id))->toBeNull()
        ->and(ContactVerificationChallenge::query()->find($recent->id))->not->toBeNull();
});

test('expired verification challenges cannot verify either channel', function () {
    $challenge = makeVerificationChallenge(['expires_at' => now()->subSecond()]);
    $service = app(ContactVerificationService::class);

    expect(fn () => $service->confirmEmail($challenge->id, 'email-token'))->toThrow(ValidationException::class);
    expect(fn () => $service->confirmPhone($challenge->id, '123456'))->toThrow(ValidationException::class);
});

test('email verification route validates the signed single-use link and returns to the booking form', function () {
    $this->actingAs(User::factory()->create(['name' => 'Test Learner', 'email' => 'learner@example.com', 'phone' => '+40722123456']));
    $challenge = makeVerificationChallenge();
    $url = URL::temporarySignedRoute('verification.email.confirm', now()->addMinutes(10), [
        'challenge' => $challenge->id,
        'token' => 'email-token',
    ]);

    $savedSlot = '2026-10-27T09:00:00+02:00';
    $this->withSession(['contact_verification_id' => $challenge->id, 'contact_verification_form_data' => [
        'name' => 'Test Learner',
        'email' => 'learner@example.com',
        'phone' => '+40722123456',
        'session_goal' => 'Work conversations',
        'starts_at' => $savedSlot,
    ]])->get($url)->assertRedirect(route('home', ['slot' => $savedSlot]));
    $this->get(route('home', ['slot' => $savedSlot]))
        ->assertOk()
        ->assertSee('value="Test Learner"', false)
        ->assertSee('value="Work conversations"', false);
    expect($challenge->fresh()->email_verified_at)->not->toBeNull();
});

test('email verification return to a bundle shows the separate free-session offer without bundle verification controls', function () {
    $this->actingAs(User::factory()->create(['email' => 'learner@example.com', 'phone' => '+40722123456']));
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $challenge = makeVerificationChallenge(['return_to' => 'purchase', 'return_bundle_id' => $bundle->id]);
    $url = URL::temporarySignedRoute('verification.email.confirm', now()->addMinutes(10), [
        'challenge' => $challenge->id,
        'token' => 'email-token',
    ]);

    config(['services.stripe.checkout_enabled' => true, 'services.stripe.secret' => 'sk_test_fake', 'services.stripe.webhook_secret' => 'whsec_test']);
    $this->withSession(['contact_verification_id' => $challenge->id])->get($url)->assertRedirect(route('purchase', ['bundle' => $bundle, 'verification_id' => $challenge->id]));
    $this->get(route('purchase', ['bundle' => $bundle, 'verification_id' => $challenge->id]))
        ->assertOk()
        ->assertSee(__('public.purchase_free_intro_notice'))
        ->assertDontSee(__('public.verification_code_label'));
    expect($challenge->fresh()->email_verified_at)->not->toBeNull();
});

test('email verification token is single-use and phone code verifies only once', function () {
    $challenge = makeVerificationChallenge();
    $service = app(ContactVerificationService::class);

    expect($service->confirmEmail($challenge->id, 'email-token')->email_verified_at)->not->toBeNull();
    expect(fn () => $service->confirmEmail($challenge->id, 'email-token'))->toThrow(ValidationException::class);
    expect($service->confirmPhone($challenge->id, '123456')->phone_verified_at)->not->toBeNull();
    expect(fn () => $service->confirmPhone($challenge->id, '123456'))->toThrow(ValidationException::class);
});

test('email code verifies once and locks after five incorrect attempts', function () {
    $challenge = makeVerificationChallenge([
        'email_code_hash' => hash_hmac('sha256', '654321', (string) config('app.key')),
        'email_attempts' => 0,
    ]);
    $service = app(ContactVerificationService::class);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        expect(fn () => $service->confirmEmailCode($challenge->id, '000000'))->toThrow(ValidationException::class);
    }

    expect($challenge->fresh()->email_attempts)->toBe(5);
    expect(fn () => $service->confirmEmailCode($challenge->id, '654321'))->toThrow(ValidationException::class);

    $valid = makeVerificationChallenge([
        'customer_email' => 'email-code-success@example.com',
        'customer_phone' => '+40722123458',
        'email_code_hash' => hash_hmac('sha256', '654321', (string) config('app.key')),
    ]);
    expect($service->confirmEmailCode($valid->id, '654321')->email_verified_at)->not->toBeNull();
    expect(fn () => $service->confirmEmailCode($valid->id, '654321'))->toThrow(ValidationException::class);
});

test('phone verification limits incorrect code attempts', function () {
    $challenge = makeVerificationChallenge();
    $service = app(ContactVerificationService::class);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        expect(fn () => $service->confirmPhone($challenge->id, '000000'))->toThrow(ValidationException::class);
    }

    expect($challenge->fresh()->phone_attempts)->toBe(5);
    expect(fn () => $service->confirmPhone($challenge->id, '123456'))->toThrow(ValidationException::class);
});

test('free introduction is created only when both contact channels are verified', function () {
    $this->actingAs(User::factory()->create(['email' => 'learner@example.com', 'phone' => '+40722123456']));
    $day = Carbon::parse('2026-10-27 09:00:00', AvailabilityService::TIMEZONE);
    $this->travelTo($day->copy()->subHour());
    addVerificationAvailability($day);
    $challenge = makeVerificationChallenge(['email_verified_at' => now(), 'phone_verified_at' => now()]);

    $this->get(route('booking.create', ['type' => 'intro', 'verification_id' => $challenge->id]))
        ->assertOk()
        ->assertSee(__('public.verification_title'))
        ->assertSee(__('public.verification_status_verified'));

    $this->withSession(['contact_verification_id' => $challenge->id, 'locale' => 'en'])
        ->post('/book', [
            'type' => 'intro', 'verification_id' => $challenge->id, 'starts_at' => '2026-10-27T09:00:00+02:00',
            'name' => 'Test Learner', 'email' => 'learner@example.com', 'phone' => '+40722123456',
            'whatsapp_service_consent' => '1', 'english_level' => 'B1', 'session_goal' => 'Work conversations', 'accepted_policy' => '1',
        ])->assertRedirect();

    expect(Booking::query()->where('status', 'confirmed')->count())->toBe(1)
        ->and($challenge->fresh()->consumed_at)->not->toBeNull();
});

test('email-only mode permits a free introduction after email verification without WhatsApp consent', function () {
    $this->actingAs(User::factory()->create(['email' => 'email-only-booking@example.com', 'phone' => '+40722123459']));
    config(['services.contact_verification.mode' => 'email_only']);
    $day = Carbon::parse('2026-10-27 09:00:00', AvailabilityService::TIMEZONE);
    $this->travelTo($day->copy()->subHour());
    addVerificationAvailability($day);
    $challenge = makeVerificationChallenge([
        'customer_email' => 'email-only-booking@example.com',
        'customer_phone' => '+40722123459',
        'email_verified_at' => now(),
        'phone_verified_at' => null,
    ]);

    $this->withSession(['contact_verification_id' => $challenge->id, 'locale' => 'en'])
        ->post('/book', [
            'type' => 'intro',
            'verification_id' => $challenge->id,
            'starts_at' => '2026-10-27T09:00:00+02:00',
            'name' => 'Email Only Learner',
            'email' => 'email-only-booking@example.com',
            'phone' => '+40722123459',
            'english_level' => 'B1',
            'session_goal' => 'Work conversations',
            'accepted_policy' => '1',
        ])->assertRedirect();

    expect(Booking::query()->where('customer_email', 'email-only-booking@example.com')->where('status', 'confirmed')->count())->toBe(1)
        ->and(WhatsAppContact::query()->count())->toBe(0)
        ->and($challenge->fresh()->consumed_at)->not->toBeNull();
});

test('verified contacts are persisted on bundle checkout without adding a free session to the bundle', function () {
    config(['services.stripe.secret' => 'sk_test_fake', 'services.stripe.webhook_secret' => 'whsec_test', 'services.stripe.checkout_enabled' => true]);
    $stripe = new FakeStripeGateway;
    app()->instance(StripeGateway::class, $stripe);
    app()->instance(FakeStripeGateway::class, $stripe);
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $challenge = makeVerificationChallenge([
        'customer_email' => 'stripe-customer@example.com',
        'customer_phone' => '+40722123456',
        'return_to' => 'purchase',
        'return_bundle_id' => $bundle->id,
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);

    $payment = app(StripeCheckoutService::class)->start($bundle, [
        'name' => 'Stripe Test Customer', 'email' => 'stripe-customer@example.com', 'phone' => '+40722123456',
    ], 'AbCdEf0123456789AbCdEf0123456789AbCdEf0123456789', 'en', $challenge);
    $entitlement = $payment->entitlement->fresh();
    expect($entitlement->customer_email_verified_at)->not->toBeNull()
        ->and($entitlement->customer_phone_verified_at)->not->toBeNull()
        ->and($entitlement->free_credits_granted)->toBe(0);

    $entitlement->forceFill(['status' => 'active', 'activated_at' => now(), 'expires_at' => now()->addMonths(3)])->save();
    expect($entitlement->fresh()->free_credits_granted)->toBe(0)
        ->and($entitlement->fresh()->free_credits_remaining)->toBe(0)
        ->and(FreeSessionClaim::query()->count())->toBe(0);
});

test('verified challenge cannot be reused with a different email or phone', function () {
    $this->actingAs(User::factory()->create(['email' => 'attacker@example.com', 'phone' => '+40722123456']));
    $day = Carbon::parse('2026-10-27 09:00:00', AvailabilityService::TIMEZONE);
    $this->travelTo($day->copy()->subHour());
    addVerificationAvailability($day);
    $challenge = makeVerificationChallenge(['email_verified_at' => now(), 'phone_verified_at' => now()]);

    $this->withSession(['contact_verification_id' => $challenge->id, 'locale' => 'en'])
        ->from('/book?type=intro')
        ->post('/book', [
            'type' => 'intro', 'verification_id' => $challenge->id, 'starts_at' => '2026-10-27T09:00:00+02:00',
            'name' => 'Test Learner', 'email' => 'attacker@example.com', 'phone' => '+40722123456',
            'whatsapp_service_consent' => '1', 'english_level' => 'B1', 'session_goal' => 'Work conversations', 'accepted_policy' => '1',
        ])->assertSessionHasErrors('verification');

    expect(Booking::query()->count())->toBe(0);
});

test('contact verification endpoints require the authenticated account and session-owned challenge', function () {
    $challenge = makeVerificationChallenge();
    $url = URL::temporarySignedRoute('verification.email.confirm', now()->addMinutes(10), [
        'challenge' => $challenge->id,
        'token' => 'email-token',
    ]);

    $this->postJson(route('verification.start'), [])->assertUnauthorized();
    $this->postJson(route('verification.email-code.confirm'), [])->assertUnauthorized();
    $this->postJson(route('verification.phone.confirm'), [])->assertUnauthorized();
    $this->getJson($url)->assertUnauthorized();
});

test('inline verification skips an email channel already verified on the current account', function () {
    $user = User::factory()->create([
        'email' => 'verified-email@example.com',
        'phone' => '+40722123460',
        'email_verified_at' => now(),
        'phone_verified_at' => null,
    ]);
    $slot = now()->addDays(2)->toIso8601String();

    $response = $this->actingAs($user)->withSession(['locale' => 'en'])->postJson(route('verification.start'), [
        'email' => $user->email,
        'phone' => $user->phone,
        'return_to' => 'booking',
        'type' => 'intro',
        'starts_at' => $slot,
        'english_level' => 'B1',
        'session_goal' => 'Work conversations',
        'accepted_policy' => '1',
        'whatsapp_service_consent' => '1',
        'locale' => 'en',
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'verification_required')
        ->assertJsonPath('next_step', 'contact_verification')
        ->assertJsonPath('slot', $slot)
        ->assertJsonPath('eligibility', null);
    expect($response->json('html'))->toContain(__('public.verification_status_verified'));

    $challenge = ContactVerificationChallenge::query()->where('customer_email', $user->email)->firstOrFail();
    expect($challenge->email_verified_at)->not->toBeNull()
        ->and($challenge->email_sent_at)->toBeNull()
        ->and($challenge->phone_verified_at)->toBeNull()
        ->and($challenge->phone_sent_at)->not->toBeNull()
        ->and(app(FakeWhatsAppGateway::class)->sent)->toHaveCount(1)
        ->and(Booking::query()->count())->toBe(0)
        ->and(FreeSessionClaim::query()->count())->toBe(0);
});

test('inline phone confirmation returns eligibility without creating a booking, hold, or claim', function () {
    $user = User::factory()->create([
        'email' => 'verified-email-phone-pending@example.com',
        'phone' => '+40722123461',
        'email_verified_at' => now(),
        'phone_verified_at' => null,
    ]);
    $challenge = makeVerificationChallenge([
        'customer_email' => $user->email,
        'customer_phone' => $user->phone,
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)->withSession([
        'locale' => 'en',
        'contact_verification_id' => $challenge->id,
        'contact_verification_form_data' => ['starts_at' => '2026-11-12T09:00:00+02:00'],
    ])->postJson(route('verification.phone.confirm'), [
        'verification_id' => $challenge->id,
        'phone_code' => '123456',
        'phone' => $user->phone,
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'eligible')
        ->assertJsonPath('next_step', 'eligible')
        ->assertJsonPath('eligibility.eligible', true)
        ->assertJsonMissingPath('eligibility.email')
        ->assertJsonMissingPath('eligibility.phone');

    expect($user->fresh()->phone_verified_at)->not->toBeNull()
        ->and($challenge->fresh()->phone_verified_at)->not->toBeNull()
        ->and(Booking::query()->count())->toBe(0)
        ->and(FreeSessionClaim::query()->count())->toBe(0);
});

test('inline email and phone verification returns the next state without automatically booking', function () {
    $user = User::factory()->create([
        'email' => 'both-channels@example.com',
        'phone' => '+40722123462',
        'email_verified_at' => null,
        'phone_verified_at' => null,
    ]);
    $challenge = makeVerificationChallenge([
        'customer_email' => $user->email,
        'customer_phone' => $user->phone,
        'email_code_hash' => hash_hmac('sha256', '654321', (string) config('app.key')),
    ]);
    $session = ['locale' => 'en', 'contact_verification_id' => $challenge->id];

    $emailResponse = $this->actingAs($user)->withSession($session)->postJson(route('verification.email-code.confirm'), [
        'verification_id' => $challenge->id,
        'email_code' => '654321',
        'phone' => $user->phone,
    ]);
    $emailResponse->assertOk()->assertJsonPath('status', 'verification_required')
        ->assertJsonPath('next_step', 'contact_verification');
    expect($user->fresh()->email_verified_at)->not->toBeNull()
        ->and($user->fresh()->phone_verified_at)->toBeNull()
        ->and(Booking::query()->count())->toBe(0)
        ->and(FreeSessionClaim::query()->count())->toBe(0);

    $phoneResponse = $this->postJson(route('verification.phone.confirm'), [
        'verification_id' => $challenge->id,
        'phone_code' => '123456',
        'phone' => $user->phone,
    ]);
    $phoneResponse->assertOk()->assertJsonPath('status', 'eligible')
        ->assertJsonPath('eligibility.eligible', true);

    expect($user->fresh()->phone_verified_at)->not->toBeNull()
        ->and(Booking::query()->count())->toBe(0)
        ->and(FreeSessionClaim::query()->count())->toBe(0);
});

test('email-only inline verification does not require WhatsApp consent or send a WhatsApp message', function () {
    config([
        'services.contact_verification.mode' => 'email_only',
        'services.whatsapp.enabled' => false,
        'services.whatsapp.access_token' => null,
        'services.whatsapp.phone_number_id' => null,
    ]);
    $user = User::factory()->create([
        'email' => 'email-only-inline@example.com',
        'phone' => '+40722123463',
        'email_verified_at' => null,
        'phone_verified_at' => null,
    ]);

    $response = $this->actingAs($user)->withSession(['locale' => 'en'])->postJson(route('verification.start'), [
        'email' => $user->email,
        'phone' => $user->phone,
        'return_to' => 'booking',
        'type' => 'intro',
        'starts_at' => now()->addDays(2)->toIso8601String(),
        'english_level' => 'B1',
        'session_goal' => 'Work conversations',
        'accepted_policy' => '1',
        'locale' => 'en',
    ]);

    $response->assertOk()->assertJsonPath('status', 'verification_required');
    $challenge = ContactVerificationChallenge::query()->where('customer_email', $user->email)->firstOrFail();
    expect($challenge->email_sent_at)->not->toBeNull()
        ->and($challenge->phone_sent_at)->toBeNull()
        ->and(app(FakeWhatsAppGateway::class)->sent)->toBeEmpty();

    $challenge->update(['email_code_hash' => hash_hmac('sha256', '654321', (string) config('app.key'))]);
    $emailConfirmed = $this->postJson(route('verification.email-code.confirm'), [
        'verification_id' => $challenge->id,
        'email_code' => '654321',
        'phone' => $user->phone,
    ]);
    $emailConfirmed->assertOk()->assertJsonPath('status', 'eligible')
        ->assertJsonPath('eligibility.eligible', true);
    expect($emailConfirmed->json('html'))->not->toContain('data-phone-change-controls');

    expect($user->fresh()->email_verified_at)->not->toBeNull()
        ->and(Booking::query()->count())->toBe(0)
        ->and(FreeSessionClaim::query()->count())->toBe(0);
});

test('verification rejects a phone changed after the challenge was issued', function () {
    $user = User::factory()->create([
        'email' => 'phone-changed@example.com',
        'phone' => '+40722123464',
        'email_verified_at' => now(),
    ]);
    $challenge = makeVerificationChallenge([
        'customer_email' => $user->email,
        'customer_phone' => '+40722123464',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)->withSession(['contact_verification_id' => $challenge->id])
        ->postJson(route('verification.phone.confirm'), [
            'verification_id' => $challenge->id,
            'phone_code' => '123456',
            'phone' => '+40722123465',
        ])->assertUnprocessable()->assertJsonPath('status', 'contact_changed')->assertJsonValidationErrors(['phone']);

    expect($user->fresh()->phone)->toBe('+40722123464')
        ->and($user->fresh()->phone_verified_at)->toBeNull()
        ->and(Booking::query()->count())->toBe(0);
});

test('verification challenge cannot be confirmed from another authenticated account', function () {
    $challenge = makeVerificationChallenge();
    $otherUser = User::factory()->create(['email' => 'other-account@example.com']);

    $this->actingAs($otherUser)->withSession(['contact_verification_id' => $challenge->id])
        ->postJson(route('verification.phone.confirm'), [
            'verification_id' => $challenge->id,
            'phone_code' => '123456',
            'phone' => $challenge->customer_phone,
        ])->assertForbidden();

    expect($challenge->fresh()->phone_verified_at)->toBeNull();
});

test('free-session eligibility uses normalized email OR phone and reuses only teacher-cancelled claims without bookings', function () {
    $eligibility = app(FreeSessionEligibilityService::class);

    expect($eligibility->check('New-Learner@example.com', '+40 722 123 456'))
        ->toBe(['status' => 'eligible', 'eligible' => true]);

    FreeSessionClaim::query()->create([
        'customer_email' => 'different@example.com',
        'customer_phone' => '+40722123456',
        'status' => 'used',
        'verified_at' => now(),
        'used_at' => now(),
    ]);
    expect($eligibility->check('new-learner@example.com', '+40722123456'))
        ->toBe(['status' => 'already_used', 'eligible' => false]);

    FreeSessionClaim::query()->create([
        'customer_email' => 'other-phone@example.com',
        'customer_phone' => '+40722123457',
        'status' => 'teacher_cancelled',
        'verified_at' => now(),
    ]);
    expect($eligibility->check('other-phone@example.com', '+40722123457'))
        ->toBe(['status' => 'eligible', 'eligible' => true]);

    FreeSessionClaim::query()->create([
        'customer_email' => 'other-phone@example.com',
        'customer_phone' => '+40722123458',
        'status' => 'no_show',
        'verified_at' => now(),
        'used_at' => now(),
    ]);
    expect($eligibility->check('other-phone@example.com', '+40722123457'))
        ->toBe(['status' => 'already_used', 'eligible' => false]);
});

test('inline verification skips a phone channel already verified for the exact current phone', function () {
    $user = User::factory()->create([
        'email' => 'verified-phone@example.com',
        'phone' => '+40722123466',
        'email_verified_at' => null,
        'phone_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)->withSession(['locale' => 'en'])->postJson(route('verification.start'), [
        'email' => $user->email,
        'phone' => $user->phone,
        'return_to' => 'booking',
        'type' => 'intro',
        'starts_at' => now()->addDays(2)->toIso8601String(),
        'english_level' => 'B1',
        'session_goal' => 'Work conversations',
        'accepted_policy' => '1',
        'locale' => 'en',
    ]);

    $response->assertOk()->assertJsonPath('status', 'verification_required');
    $challenge = ContactVerificationChallenge::query()->where('customer_email', $user->email)->firstOrFail();
    expect($challenge->phone_verified_at)->not->toBeNull()
        ->and($challenge->phone_sent_at)->toBeNull()
        ->and($challenge->email_verified_at)->toBeNull()
        ->and($challenge->email_sent_at)->not->toBeNull()
        ->and(app(FakeWhatsAppGateway::class)->sent)->toBeEmpty();
});

test('a previously verified phone is not skipped when the learner submits a different number', function () {
    $user = User::factory()->create([
        'email' => 'changed-verified-phone@example.com',
        'phone' => '+40722123467',
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)->withSession(['locale' => 'en'])->postJson(route('verification.start'), [
        'email' => $user->email,
        'phone' => '+40722123468',
        'return_to' => 'booking',
        'type' => 'intro',
        'starts_at' => now()->addDays(2)->toIso8601String(),
        'english_level' => 'B1',
        'session_goal' => 'Work conversations',
        'accepted_policy' => '1',
        'whatsapp_service_consent' => '1',
        'locale' => 'en',
    ]);

    $response->assertOk()->assertJsonPath('status', 'verification_required');
    $challenge = ContactVerificationChallenge::query()->where('customer_phone', '+40722123468')->firstOrFail();
    expect($challenge->phone_verified_at)->toBeNull()
        ->and($challenge->phone_sent_at)->not->toBeNull()
        ->and(app(FakeWhatsAppGateway::class)->sent)->toHaveCount(1);
});

test('the landing page performs a read-only eligibility check for already verified contacts', function () {
    app()->setLocale('en');

    $user = User::factory()->create([
        'email' => 'already-verified@example.com',
        'phone' => '+40722123469',
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);

    $this->actingAs($user)->get(route('home'))
        ->assertOk()
        ->assertSee('data-free-session-eligibility="eligible"', false)
        ->assertSee(__('public.verification_eligible'))
        ->assertSee('data-phone-change-controls', false)
        ->assertSee('data-contacts-verified="true"', false);

    expect(Booking::query()->count())->toBe(0)
        ->and(FreeSessionClaim::query()->count())->toBe(0);

    FreeSessionClaim::query()->create([
        'customer_email' => 'someone-else@example.com',
        'customer_phone' => $user->phone,
        'status' => 'used',
        'verified_at' => now(),
        'used_at' => now(),
    ]);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee(__('public.booking_account_validated_title'))
        ->assertSee('data-booking-dashboard-redirect="'.route('dashboard', ['view' => 'purchases']).'"', false)
        ->assertSee('Go to my purchases')
        ->assertDontSee('data-used-free-bundle-modal', false)
        ->assertDontSee(__('public.booking_submit_intro'));

    expect(Booking::query()->count())->toBe(0)
        ->and(BookingCallEvent::query()->count())->toBe(0)
        ->and(FreeSessionClaim::query()->count())->toBe(1);
});

test('final inline verification returns only an already-used outcome when either identifier has a prior claim', function () {
    app()->setLocale('en');

    $user = User::factory()->create([
        'email' => 'claim-owner@example.com',
        'phone' => '+40722123470',
        'email_verified_at' => now(),
        'phone_verified_at' => null,
    ]);
    FreeSessionClaim::query()->create([
        'customer_email' => 'another-account@example.com',
        'customer_phone' => $user->phone,
        'status' => 'used',
        'verified_at' => now(),
        'used_at' => now(),
    ]);
    $challenge = makeVerificationChallenge([
        'customer_email' => $user->email,
        'customer_phone' => $user->phone,
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)->withSession(['contact_verification_id' => $challenge->id])
        ->postJson(route('verification.phone.confirm'), [
            'verification_id' => $challenge->id,
            'phone_code' => '123456',
            'phone' => $user->phone,
        ]);

    $response->assertOk()
        ->assertJsonPath('status', 'already_used')
        ->assertJsonPath('eligibility.eligible', false)
        ->assertJsonMissingPath('eligibility.email')
        ->assertJsonMissingPath('eligibility.phone');

    expect($response->json('html'))->toContain(__('public.booking_account_validated_title'))
        ->and($response->json('html'))->toContain('data-booking-dashboard-redirect="'.route('dashboard', ['view' => 'purchases']).'"')
        ->and($response->json('html'))->toContain('Go to my purchases')
        ->and(Booking::query()->count())->toBe(0)
        ->and(BookingCallEvent::query()->count())->toBe(0)
        ->and(FreeSessionClaim::query()->count())->toBe(1);
});

test('inline verification returns distinct invalid, expired, consumed, and locked challenge states', function () {
    $user = User::factory()->create([
        'email' => 'challenge-states@example.com',
        'phone' => '+40722123471',
        'email_verified_at' => null,
        'phone_verified_at' => null,
    ]);
    $invalid = makeVerificationChallenge([
        'customer_email' => $user->email,
        'customer_phone' => $user->phone,
    ]);
    $this->actingAs($user)->withSession(['contact_verification_id' => $invalid->id])
        ->postJson(route('verification.email-code.confirm'), [
            'verification_id' => $invalid->id,
            'email_code' => '000000',
            'phone' => $user->phone,
        ])->assertUnprocessable()->assertJsonPath('status', 'invalid_code');

    $expired = makeVerificationChallenge([
        'customer_email' => $user->email,
        'customer_phone' => $user->phone,
        'expires_at' => now()->subSecond(),
    ]);
    $this->withSession(['contact_verification_id' => $expired->id])
        ->postJson(route('verification.phone.confirm'), [
            'verification_id' => $expired->id,
            'phone_code' => '123456',
            'phone' => $user->phone,
        ])->assertUnprocessable()->assertJsonPath('status', 'expired');

    $used = makeVerificationChallenge([
        'customer_email' => $user->email,
        'customer_phone' => $user->phone,
        'consumed_at' => now(),
    ]);
    $usedResponse = $this->withSession(['contact_verification_id' => $used->id])
        ->postJson(route('verification.phone.confirm'), [
            'verification_id' => $used->id,
            'phone_code' => '123456',
            'phone' => $user->phone,
        ]);
    $usedResponse->assertUnprocessable()->assertJsonPath('status', 'challenge_used');

    $locked = makeVerificationChallenge([
        'customer_email' => $user->email,
        'customer_phone' => $user->phone,
        'email_attempts' => 5,
    ]);
    $this->withSession(['contact_verification_id' => $locked->id])
        ->postJson(route('verification.email-code.confirm'), [
            'verification_id' => $locked->id,
            'email_code' => '123456',
            'phone' => $user->phone,
        ])->assertUnprocessable()->assertJsonPath('status', 'attempts_locked');
});

test('verification start returns explicit resend and hourly rate-limit states', function () {
    $user = User::factory()->create([
        'email' => 'throttled-verification@example.com',
        'phone' => '+40722123472',
        'email_verified_at' => null,
        'phone_verified_at' => null,
    ]);
    $payload = [
        'email' => $user->email,
        'phone' => $user->phone,
        'return_to' => 'booking',
        'type' => 'intro',
        'starts_at' => now()->addDays(2)->toIso8601String(),
        'english_level' => 'B1',
        'session_goal' => 'Work conversations',
        'accepted_policy' => '1',
        'whatsapp_service_consent' => '1',
        'locale' => 'en',
    ];

    $emailLimitKey = 'contact-verification:email:'.hash('sha256', $user->email);
    RateLimiter::hit($emailLimitKey, 3600);
    RateLimiter::hit($emailLimitKey, 3600);
    RateLimiter::hit($emailLimitKey, 3600);
    RateLimiter::hit($emailLimitKey, 3600);
    RateLimiter::hit($emailLimitKey, 3600);
    $this->actingAs($user)->withSession(['locale' => 'en'])->postJson(route('verification.start'), $payload)
        ->assertTooManyRequests()->assertJsonPath('status', 'rate_limited');

    RateLimiter::clear($emailLimitKey);
    ContactVerificationChallenge::query()->create([
        'id' => (string) Str::uuid(),
        'customer_email' => $user->email,
        'customer_phone' => $user->phone,
        'locale' => 'en',
        'return_to' => 'booking',
        'email_token_hash' => hash('sha256', 'existing-token'),
        'email_code_hash' => hash_hmac('sha256', '123456', (string) config('app.key')),
        'phone_code_hash' => hash_hmac('sha256', '123456', (string) config('app.key')),
        'email_sent_at' => now(),
        'phone_sent_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);

    $this->postJson(route('verification.start'), $payload)
        ->assertTooManyRequests()->assertJsonPath('status', 'resend_wait');
});

test('verification delivery failure returns an explicit state and never verifies a channel', function () {
    $user = User::factory()->create([
        'email' => 'delivery-failure@example.com',
        'phone' => '+40722123473',
        'email_verified_at' => now(),
    ]);
    $gateway = Mockery::mock(WhatsAppGateway::class);
    $gateway->shouldReceive('sendTemplate')->once()->andThrow(new RuntimeException('provider unavailable'));
    app()->instance(WhatsAppGateway::class, $gateway);

    $response = $this->actingAs($user)->withSession(['locale' => 'en'])->postJson(route('verification.start'), [
        'email' => $user->email,
        'phone' => $user->phone,
        'return_to' => 'booking',
        'type' => 'intro',
        'starts_at' => now()->addDays(2)->toIso8601String(),
        'english_level' => 'B1',
        'session_goal' => 'Work conversations',
        'accepted_policy' => '1',
        'whatsapp_service_consent' => '1',
        'locale' => 'en',
    ]);

    $response->assertStatus(503)->assertJsonPath('status', 'delivery_failed');
    $challenge = ContactVerificationChallenge::query()->where('customer_email', $user->email)->firstOrFail();
    expect($challenge->isUsable())->toBeFalse()
        ->and($user->fresh()->phone_verified_at)->toBeNull()
        ->and(Booking::query()->count())->toBe(0)
        ->and(FreeSessionClaim::query()->count())->toBe(0);
});

test('starting verification for a changed phone invalidates the previous account challenge', function () {
    $user = User::factory()->create([
        'email' => 'replace-challenge@example.com',
        'phone' => '+40722123474',
        'email_verified_at' => now(),
        'phone_verified_at' => null,
    ]);
    $payload = [
        'email' => $user->email,
        'phone' => '+40722123474',
        'return_to' => 'booking',
        'type' => 'intro',
        'starts_at' => now()->addDays(2)->toIso8601String(),
        'english_level' => 'B1',
        'session_goal' => 'Work conversations',
        'accepted_policy' => '1',
        'whatsapp_service_consent' => '1',
        'locale' => 'en',
    ];

    $first = $this->actingAs($user)->withSession(['locale' => 'en'])->postJson(route('verification.start'), $payload);
    $first->assertOk();
    $firstChallenge = ContactVerificationChallenge::query()->where('customer_phone', '+40722123474')->firstOrFail();

    $payload['phone'] = '+40722123475';
    $second = $this->postJson(route('verification.start'), $payload);
    $second->assertOk()->assertJsonPath('status', 'verification_required');
    $secondChallenge = ContactVerificationChallenge::query()->where('customer_phone', '+40722123475')->firstOrFail();

    expect($firstChallenge->fresh()->isUsable())->toBeFalse()
        ->and($firstChallenge->fresh()->consumed_at)->not->toBeNull()
        ->and($secondChallenge->isUsable())->toBeTrue()
        ->and(session('contact_verification_id'))->toBe($secondChallenge->id);
});
