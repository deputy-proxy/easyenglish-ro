<?php

use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\BundleDefinition;
use App\Models\BundleEntitlement;
use App\Models\User;
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
        ->assertSee('De data asta, ai ceva de spus.')
        ->assertSee('În engleză.')
        ->assertSee('data-mobile-conversation')
        ->assertSee('hero-visual-mobile relative z-10', false)
        ->assertSee('role="group"', false)
        ->assertSee('hero-conversation-mobile absolute inset-x-2 bottom-2 z-20', false)
        ->assertSee('grid h-[260px] grid-cols-2 gap-0', false)
        ->assertSee('data-conversation-track')
        ->assertSee('What can I help you with today?')
        ->assertSee('I have a presentation next week.')
        ->assertSee('renewable energy')
        ->assertSee('Can we practise questions, too?')
        ->assertSee('I feel more confident now.')
        ->assertSee('pb-7 pt-[100px]', false)
        ->assertSee('my-5 max-w-[38ch]', false)
        ->assertSee('h-[260px] w-full object-cover object-left', false)
        ->assertSee('h-[260px] w-full object-cover object-right', false)
        ->assertSee('Autentificare')
        ->assertSee('href="'.url('/admin/login').'"', false)
        ->assertSee('h-[20.87px] items-center justify-center rounded-[6px] border border-ink', false)
        ->assertSee('PENTRU MOMENTELE CARE CONTEAZĂ')
        ->assertSee('Profesorul pregătește. Tu exersezi.')
        ->assertSee('156')
        ->assertSee('390')
        ->assertSee('780')
        ->assertSee('15 minute pe sesiune')
        ->assertSee('Europe/Bucharest')
        ->assertSee('Data viitoare când ai ceva de spus, spune-o în engleză.')
        ->assertSee('Rezervă prima conversație gratuită.')
        ->assertSee('data-week-status', false)
        ->assertSee('Toate cele 7 zile sunt vizibile simultan. Alege direct un interval.')
        ->assertSee('grid min-w-0 grid-cols-7 gap-1 sm:gap-2', false)
        ->assertSee('min-h-10 rounded-[7px]', false)
        ->assertSee('Pachete simple. Engleză în ritmul tău.')
        ->assertSee('Alegi pachetul potrivit și îți programezi conversațiile atunci când ai timp.')
        ->assertSee('O singură sesiune introductivă gratuită per persoană.')
        ->assertSee('1 sesiune gratuită + 4 sesiuni plătite')
        ->assertSee('5 sesiuni în total')
        ->assertSee('1 sesiune gratuită + 10 sesiuni plătite')
        ->assertSee('11 sesiuni în total')
        ->assertSee('1 sesiune gratuită + 20 sesiuni plătite')
        ->assertSee('21 sesiuni în total');
});

test('public landing page versions logo and hero images using their file contents', function () {
    $assets = [
        'images/logo-white.svg',
        'images/hero-teacher.png',
        'images/hero-student.png',
    ];

    $response = $this->get('/')->assertOk();

    foreach ($assets as $asset) {
        $response->assertSee(
            'src="'.asset($asset).'?v='.hash_file('sha256', public_path($asset)).'"',
            false,
        );
    }
});

test('visitor can switch the public experience to English without an account', function () {
    $this->from('/')->post('/language', ['locale' => 'en'])->assertRedirect('/');

    $this->get('/')->assertOk()->assertSee('This time, you have something to say.')
        ->assertSee('FOR THE MOMENTS THAT MATTER')
        ->assertSee('Your teacher prepares. You practise.')
        ->assertSee('Simple bundles. English at your pace.')
        ->assertSee('Choose the bundle that suits you and schedule your conversations whenever you have time.')
        ->assertSee('One free introductory session per person.')
        ->assertSee('1 free session + 4 paid sessions')
        ->assertSee('5 sessions in total')
        ->assertSee('1 free session + 10 paid sessions')
        ->assertSee('11 sessions in total')
        ->assertSee('1 free session + 20 paid sessions')
        ->assertSee('21 sessions in total')
        ->assertSee('All 7 days are visible at once. Choose a time directly.')
        ->assertSee('Next time you have something to say, say it in English.')
        ->assertSee('Book your first free conversation.')
        ->assertSee('bg-primary', false)
        ->assertSee('bg-surface-subtle', false)
        ->assertSee('text-success', false)
        ->assertDontSee('bg-green', false)
        ->assertDontSee('text-green', false)
        ->assertDontSee('border-green', false)
        ->assertDontSee('bg-lime', false)
        ->assertSee('MOST POPULAR');
});

test('brand palette exposes semantic primary, surface, text, and success tokens', function () {
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($styles)
        ->toContain('--color-primary: #1450df')
        ->toContain('--color-ink: #14234b')
        ->toContain('--color-muted: #53617b')
        ->toContain('--color-surface-subtle: #f5f7fc')
        ->toContain('--color-line: #e1e7f0')
        ->toContain('--color-success: #176b4d')
        ->toContain('--color-focus: #1450df')
        ->toContain(':where(a, button, summary):focus-visible');
});

test('weekly availability empty and low-availability messages are localized', function () {
    app()->setLocale('ro');

    expect(__('public.availability_empty'))->toContain('Nu există intervale disponibile')
        ->and(__('public.availability_low'))->toContain('Sunt puține intervale disponibile');

    app()->setLocale('en');

    expect(__('public.availability_empty'))->toContain('No times are available')
        ->and(__('public.availability_low'))->toContain('Only a few times are available');
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
    $this->actingAs(User::factory()->create(['email' => 'learner@example.com']));
    $day = CarbonImmutable::parse('2026-10-27', 'Europe/Bucharest');
    addPublicAvailability($day);

    $this->from('/book?type=intro')->post('/book', [
        'type' => 'intro', 'starts_at' => '2026-10-27T09:00:00+02:00',
        'name' => 'Test Learner', 'email' => 'learner@example.com', 'phone' => '+40722123456', 'whatsapp_service_consent' => '1',
        'english_level' => 'B1', 'session_goal' => 'Work conversations', 'accepted_policy' => '1',
    ])->assertRedirect('/book?type=intro')->assertSessionHasErrors('verification');

    expect(Booking::query()->count())->toBe(0);
});

test('verified account can book its free introduction without repeating contact verification', function () {
    $this->actingAs(User::factory()->create([
        'email' => 'verified-learner@example.com',
        'phone' => '+40722123456',
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
        'english_level' => 'B1',
        'session_goal' => 'Work conversations',
    ]));
    $day = CarbonImmutable::parse('2026-10-27', 'Europe/Bucharest');
    addPublicAvailability($day);

    $this->post('/book', [
        'type' => 'intro', 'starts_at' => '2026-10-27T09:00:00+02:00',
        'name' => 'Verified Learner', 'email' => 'verified-learner@example.com', 'phone' => '+40722123456',
        'whatsapp_service_consent' => '1', 'english_level' => 'B1', 'session_goal' => 'Work conversations',
        'accepted_policy' => '1',
    ])->assertRedirect();

    expect(Booking::query()->where('customer_email', 'verified-learner@example.com')->where('status', 'confirmed')->count())->toBe(1);
});

test('authenticated bundle customer can book a slot and receives a signed confirmation', function () {
    $this->actingAs(User::factory()->create(['email' => 'learner@example.com']));
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
    $signedFormUrl = URL::signedRoute('booking.create', ['type' => 'bundle', 'entitlement' => $entitlement->id], now()->addMinutes(30));

    $this->withSession(['locale' => 'en'])->get($signedFormUrl.'&slot='.urlencode('2026-10-27T09:30:00+02:00'))->assertOk()->assertSee('Schedule a session from your bundle');

    $signedSubmitUrl = URL::signedRoute('booking.submit', ['type' => 'bundle', 'entitlement' => $entitlement->id], now()->addMinutes(30));
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
    $this->actingAs(User::factory()->create(['email' => 'different@example.com']));
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
    $expired = URL::signedRoute('booking.create', ['type' => 'bundle', 'entitlement' => $entitlement->id], now()->subMinute());
    $this->get($expired)->assertForbidden();
});

test('bundle purchase page discloses expiry and cancellation terms before checkout is available', function () {
    $this->actingAs(User::factory()->create());
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

test('selecting a free timeslot sends guests to login and preserves the selected slot', function () {
    $url = '/book?type=intro&slot='.urlencode('2026-10-29T09:00:00+02:00');

    $this->get($url)->assertRedirect(route('login'));
    expect(session('url.intended'))->toContain('slot=');

    $this->actingAs(User::factory()->create(['email' => 'dashboard-learner@example.com']))
        ->get($url)->assertOk()->assertSee('2026-10-29T09:00:00+02:00');
});

test('selecting a bundle sends guests to login and returns them to the chosen bundle', function () {
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();

    $this->get(route('purchase', $bundle))->assertRedirect(route('login'));
    expect(session('url.intended'))->toContain('/purchase/'.$bundle->id);

    $this->actingAs(User::factory()->create())
        ->get(route('purchase', $bundle))->assertOk()->assertSee('156 RON');
});
