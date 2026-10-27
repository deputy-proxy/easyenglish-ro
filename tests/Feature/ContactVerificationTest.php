<?php

use App\Contracts\Notifications\WhatsAppGateway;
use App\Contracts\Payments\StripeGateway;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\BundleDefinition;
use App\Models\ContactVerificationChallenge;
use App\Models\WhatsAppContact;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BundleEntitlementService;
use App\Services\Payments\StripeCheckoutService;
use App\Services\Verification\ContactVerificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Support\FakeStripeGateway;
use Tests\Support\FakeWhatsAppGateway;

beforeEach(function (): void {
    config([
        'services.whatsapp.enabled' => true,
        'services.whatsapp.access_token' => 'test-token',
        'services.whatsapp.phone_number_id' => 'test-phone-id',
        'services.whatsapp.templates.contact_verification' => 'easyenglish_contact_verification',
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
        ->and($challenge->phone_code_hash)->not->toBe($code)
        ->and($code)->toMatch('/^[0-9]{6}$/')
        ->and($gateway->sent)->toHaveCount(1)
        ->and($gateway->sent[0]['components'][1])->toBe([
            'type' => 'button',
            'sub_type' => 'url',
            'index' => '0',
            'parameters' => [['type' => 'text', 'text' => $code]],
        ]);
});

test('public verification form starts the email and WhatsApp steps', function () {
    $this->from('/book?type=intro')->post(route('verification.start'), [
        'return_to' => 'booking',
        'email' => 'new-learner@example.com',
        'phone' => '+40722987654',
        'whatsapp_service_consent' => '1',
    ])->assertRedirect('/book?type=intro')->assertSessionHas('status', __('public.verification_sent'));

    $challenge = ContactVerificationChallenge::query()->where('customer_email', 'new-learner@example.com')->firstOrFail();
    expect(session('contact_verification_id'))->toBe($challenge->id)
        ->and(app(FakeWhatsAppGateway::class)->sent)->toHaveCount(1)
        ->and(WhatsAppContact::query()->where('phone', '+40722987654')->value('consent_source'))->toBe('contact_verification');
});

test('bundle checkout can start verification and preserves the bundle destination', function () {
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
    app()->instance(WhatsAppGateway::class, new class implements WhatsAppGateway
    {
        public function sendTemplate(string $recipient, string $template, string $language, array $components, string $callbackData): array
        {
            throw new RuntimeException('fake provider failure');
        }
    });

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
    $challenge = makeVerificationChallenge();
    $url = URL::temporarySignedRoute('verification.email.confirm', now()->addMinutes(10), [
        'challenge' => $challenge->id,
        'token' => 'email-token',
    ]);

    $savedSlot = '2026-10-27T09:00:00+02:00';
    $this->withSession(['contact_verification_form_data' => [
        'name' => 'Test Learner',
        'email' => 'learner@example.com',
        'phone' => '+40722123456',
        'session_goal' => 'Work conversations',
        'starts_at' => $savedSlot,
    ]])->get($url)->assertRedirect(route('booking.create', [
        'type' => 'intro', 'verification_id' => $challenge->id, 'slot' => $savedSlot,
    ]));
    $this->get(route('booking.create', ['type' => 'intro', 'verification_id' => $challenge->id, 'slot' => $savedSlot]))
        ->assertOk()
        ->assertSee('value="Test Learner"', false)
        ->assertSee('value="Work conversations"', false);
    expect($challenge->fresh()->email_verified_at)->not->toBeNull();
});

test('email verification started from bundle checkout returns to that bundle', function () {
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $challenge = makeVerificationChallenge(['return_to' => 'purchase', 'return_bundle_id' => $bundle->id]);
    $url = URL::temporarySignedRoute('verification.email.confirm', now()->addMinutes(10), [
        'challenge' => $challenge->id,
        'token' => 'email-token',
    ]);

    config(['services.stripe.checkout_enabled' => true, 'services.stripe.secret' => 'sk_test_fake', 'services.stripe.webhook_secret' => 'whsec_test']);
    $this->get($url)->assertRedirect(route('purchase', ['bundle' => $bundle, 'verification_id' => $challenge->id]));
    $this->get(route('purchase', ['bundle' => $bundle, 'verification_id' => $challenge->id]))
        ->assertOk()
        ->assertSee(__('public.bundle_verification_explainer'))
        ->assertSee(__('public.verification_code_label'));
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

test('verified contacts are persisted on bundle checkout and unlock the included free credit only when eligible', function () {
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
    $updated = app(BundleEntitlementService::class)->grantIncludedFreeCreditIfEligible($entitlement->id);
    expect($updated->free_credits_granted)->toBe(1)
        ->and($updated->free_credits_remaining)->toBe(1);
});

test('verified challenge cannot be reused with a different email or phone', function () {
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
