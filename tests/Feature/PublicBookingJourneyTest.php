<?php

use App\Contracts\CallEvents\CallEventProvider;
use App\Contracts\Notifications\WhatsAppNotificationGateway;
use App\Contracts\Notifications\WhatsAppVerificationGateway;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\BundleDefinition;
use App\Models\BundleEntitlement;
use App\Models\ContactVerificationChallenge;
use App\Models\Faq;
use App\Models\FreeSessionClaim;
use App\Models\NotificationDelivery;
use App\Models\TeacherCalendar;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\Booking\AvailabilityService;
use App\Services\CallEvents\BookingCallEventService;
use App\Services\CallEvents\OpenWaConfig;
use App\Services\Notifications\NotificationMessageFactory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Fakes\FakeCallEventProvider;
use Tests\Support\FakeWhatsAppGateway;

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
        ->assertDontSee('hero-conversation-mobile absolute inset-x-2 bottom-0 z-20', false)
        ->assertSee('Who will be listening?')
        ->assertSee('My classmates. I have five minutes.')
        ->assertSee('An introduction, three points, and a conclusion?')
        ->assertSee("That's a strong opening!", false)
        ->assertSee("Good morning. Today I'll talk about...", false)
        ->assertSee('Try speaking a little slower.')
        ->assertSee('Can we practise questions, too?')
        ->assertSee("You're going to do well!", false)
        ->assertSee('Thank you! I feel more confident now.')
        ->assertSee('grid grid-cols-2 items-end gap-0', false)
        ->assertSee('data-conversation-track')
        ->assertSee('data-conversation-viewport')
        ->assertSee('data-visible-messages="5"', false)
        ->assertSee('What can I help you with today?')
        ->assertSee('I have a presentation next week.')
        ->assertSee('data-hero-shell', false)
        ->assertSee('pt-[calc(64px+env(safe-area-inset-top,0px)+24px)]', false)
        ->assertSee('md:pt-[140px]', false)
        ->assertSeeInOrder(['images/teacher-mobile-hero.webp', 'images/student-mobile-hero.webp'])
        ->assertSee('my-5 max-w-[38ch]', false)
        ->assertSee('block aspect-[1/2] w-full object-contain object-bottom', false)
        ->assertSee('Autentificare')
        ->assertSee(route('login'), false)
        ->assertSee('inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center overflow-hidden transition-opacity hover:opacity-75', false)
        ->assertSee('images/auth-icon.svg', false)
        ->assertSee('PENTRU MOMENTELE CARE CONTEAZĂ')
        ->assertSee('Alege intervalul')
        ->assertSee('Primeste invitatia')
        ->assertSee('Incepe sa vorbesti')
        ->assertSee('Urmareste progresul')
        ->assertSee('156')
        ->assertSee('390')
        ->assertSee('780')
        ->assertSee('Flexibilitate totală')
        ->assertSee('Profesori care te înțeleg')
        ->assertSee('Înveți să vorbești, nu doar să știi.')
        ->assertDontSee('Conversație pe WhatsApp')
        ->assertDontSee('Progres real și predictibil')
        ->assertSee('Europe/Bucharest')
        ->assertSee('Nu mai aștepta să vorbești engleza perfect. Începe să o vorbești.')
        ->assertSee('Începe gratuit')
        ->assertSee('data-week-status', false)
        ->assertDontSee('Vezi rapid programul întregii săptămâni și alege ziua și intervalul dorit.')
        ->assertDontSee('Sesiunile durează 15 minute, cu o pauză de 15 minute între ele.')
        ->assertSee('data-weekgrid-mobile', false)
        ->assertSee('hidden min-w-0 grid-cols-7 gap-1 sm:grid sm:gap-2', false)
        ->assertSee('min-h-10 rounded-[7px]', false)
        ->assertSee('Pachete simple. Engleză în ritmul tău.')
        ->assertSee('Alegi pachetul potrivit și îți programezi conversațiile atunci când ai timp.')
        ->assertDontSee('O singură sesiune introductivă gratuită per persoană.')
        ->assertSee('1 sesiune gratuită + 4 sesiuni plătite')
        ->assertSee('5 sesiuni în total')
        ->assertSee('1 sesiune gratuită + 10 sesiuni plătite')
        ->assertSee('11 sesiuni în total')
        ->assertSee('1 sesiune gratuită + 20 sesiuni plătite')
        ->assertSee('21 sesiuni în total');
});

test('free session availability reveals the booking form for authenticated learners', function () {
    $user = User::factory()->create([
        'email' => 'free-session@example.com',
        'phone' => '+40722123456',
        'english_level' => null,
        'session_goal' => null,
    ]);

    $this->actingAs($user)->withSession(['locale' => 'en'])->get('/')
        ->assertSee(__('public.free_booking_details'))
        ->assertSee('data-availability-content', false)
        ->assertSee('data-booking-form-panel', false)
        ->assertSee('name="csrf-token"', false)
        ->assertSee('data-booking-form-close', false)
        ->assertSee('data-booking-starts-at', false)
        ->assertSee('action="'.route('booking.submit').'"', false)
        ->assertSee('name="type" value="intro"', false)
        ->assertSee('name="phone"', false)
        ->assertSee('name="english_level"', false)
        ->assertSee('name="session_goal"', false)
        ->assertSee('name="accepted_policy"', false);
});

test('public booking phone inputs use a valid HTML pattern', function () {
    $this->withSession(['locale' => 'en'])->get('/')
        ->assertOk()
        ->assertSee('pattern="\\+?([0-9]|\\s|\\(|\\)|\\.|-){7,32}"', false);
});

test('free session availability offers account creation first and an existing-account login branch', function () {
    $this->withSession(['locale' => 'en'])->get('/')
        ->assertSee('data-booking-form-panel', false)
        ->assertSee('name="csrf-token"', false)
        ->assertSee('data-booking-step-content', false)
        ->assertSee('data-booking-feedback', false)
        ->assertSee(__('public.booking_account_question'), false)
        ->assertSee('data-booking-register-branch', false)
        ->assertSee('data-booking-endpoint="'.route('booking.register').'"', false)
        ->assertSee('action="'.route('booking.register').'"', false)
        ->assertSee('data-booking-login-branch', false)
        ->assertSee('data-booking-branch-toggle="login"', false)
        ->assertSee('name="password_confirmation"', false)
        ->assertSee('easyenglish:availability-slot-selected', false);
});

test('guest can create an account from the free booking form and is logged in with the selected slot retained', function () {
    $slot = '2026-10-27T09:00:00+03:00';

    $this->withSession(['locale' => 'en'])->post(route('booking.register'), [
        'name' => 'New Learner',
        'email' => 'new-learner@example.com',
        'phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Speak more confidently at work',
        'session_notes' => 'A little nervous about calls.',
        'password' => 'StrongPassword123!',
        'password_confirmation' => 'StrongPassword123!',
        'starts_at' => $slot,
        'accepted_policy' => '1',
        'whatsapp_service_consent' => '1',
    ])->assertRedirect(route('home'));

    $user = User::query()->where('email', 'new-learner@example.com')->firstOrFail();

    expect(auth()->id())->toBe($user->id)
        ->and($user->phone)->toBe('+40722123456')
        ->and($user->english_level)->toBe('B1')
        ->and($user->session_goal)->toBe('Speak more confidently at work');

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('data-initial-slot="'.$slot.'"', false)
        ->assertSee(__('public.account_created_continue_booking'), false)
        ->assertSee('action="'.route('booking.submit').'"', false);
});

test('a validated account that already used its free session sees a dashboard redirect instead of package selection', function () {
    app()->setLocale('en');

    $html = view('public.partials.booking-authenticated-form', [
        'contactsVerified' => true,
        'eligibility' => ['eligible' => false, 'status' => 'already_used'],
    ])->render();

    expect($html)
        ->toContain(__('public.booking_account_validated_title'))
        ->toContain('data-booking-dashboard-redirect="'.route('dashboard', ['view' => 'purchases']).'"')
        ->toContain(__('public.booking_account_validated_link'))
        ->not->toContain('data-used-free-bundle-modal');
});

test('guest can sign in from the free booking form and keep the selected slot', function () {
    $user = User::factory()->create(['email' => 'existing-learner@example.com']);
    $slot = '2026-10-27T09:00:00+03:00';

    $this->withSession(['locale' => 'en'])->post(route('booking.login'), [
        'email' => $user->email,
        'password' => 'password',
        'starts_at' => $slot,
    ])->assertRedirect(route('home'));

    expect(auth()->id())->toBe($user->id);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('data-initial-slot="'.$slot.'"', false)
        ->assertSee('action="'.route('booking.submit').'"', false);
});

test('existing account login preserves Fortify two-factor authentication', function () {
    $user = User::factory()->withTwoFactor()->create(['email' => 'two-factor-learner@example.com']);
    $slot = '2026-10-27T09:00:00+03:00';

    $this->withSession(['locale' => 'en'])->post(route('booking.login'), [
        'email' => $user->email,
        'password' => 'password',
        'starts_at' => $slot,
    ])->assertRedirect(route('login'));

    $homeUrl = route('home').'?'.http_build_query(['slot' => $slot], '', '&', PHP_QUERY_RFC3986);
    expect(auth()->check())->toBeFalse()
        ->and(session('url.intended'))->toBe($homeUrl);

    $this->get($homeUrl)
        ->assertOk()
        ->assertSee('data-initial-slot="'.$slot.'"', false);
});

test('public landing page exposes progressive enhancement hooks for its animation system', function () {
    Faq::factory()->published()->create(['locale' => 'ro']);

    $this->get('/')
        ->assertSee('data-hero', false)
        ->assertSee('data-hero-title', false)
        ->assertSee('data-hero-image', false)
        ->assertSee('data-reveal-stagger', false)
        ->assertSee('data-reveal-item', false)
        ->assertSee('data-image-reveal', false)
        ->assertSee('data-progress-chart', false)
        ->assertSee('data-chart-shape', false)
        ->assertSee('data-chart-grid', false)
        ->assertSee('data-chart-labels', false)
        ->assertSee('data-chart-legend', false)
        ->assertSee('easyenglish:availability-loading', false)
        ->assertSee('easyenglish:availability-rendered', false)
        ->assertSee('data-faq-section', false)
        ->assertSee('data-faq-answer', false)
        ->assertSee('data-faq-chevron', false)
        ->assertSee('data-reveal-background', false);
});

test('pain-point situations render as full-width stack cards in Romanian', function () {
    $expectedCopy = [
        'ro' => [
            'Să trimiți aplicația pentru jobul pe care ți-l dorești.',
            'Să-ți susții ideea într-o ședință, fără să pierzi momentul căutând cuvintele.',
            'Să pleci în călătorie și să te descurci singur în engleză.',
            'Să intri la interviul de angajare pregătit și să lași o impresie bună.',
            'Să susții o prezentare și să răspunzi cu încredere la întrebări.',
            'Să vorbești firesc cu clienții și partenerii de afaceri.',
            'Să porți o conversație la telefon fără să te blochezi.',
        ],
        'en' => [
            'Submit the application for the job you want.',
            'Share your idea in a meeting without losing the moment while searching for words.',
            'Travel and handle things for yourself in English.',
            'Walk into a job interview prepared and make a strong impression.',
            'Give a presentation and answer questions with confidence.',
            'Speak naturally with clients and business partners.',
            'Make phone calls without struggling to find the right words.',
        ],
    ];

    $locale = 'ro';
    $copy = $expectedCopy[$locale];
    foreach ([$locale] as $activeLocale) {
        $response = $this->withSession(['locale' => 'en'])->get('/');
        $content = $response->getContent();

        $response->assertOk()
            ->assertSee('data-pain-point-stack', false)
            ->assertSee('data-pain-point-card', false)
            ->assertSee('data-pain-point-title', false)
            ->assertSee('data-pain-point-description', false)
            ->assertSee(__('public.motivation_eyebrow', [], $locale))
            ->assertSee(__('public.motivation_title', [], $locale))
            ->assertSee(__('public.motivation_text', [], $locale));

        foreach ($copy as $situation) {
            $response->assertSee($situation);
        }

        foreach (['job', 'meeting', 'travel', 'interview', 'presentation', 'clients', 'phone'] as $situation) {
            $response->assertSee(__('public.motivation_'.$situation.'_description', [], $locale));
        }

        expect(preg_match('/<section[^>]*id="why"[^>]*>(.*?)<\/section>/s', $content, $matches))->toBe(1)
            ->and(substr_count($matches[1], 'data-pain-point-card'))->toBe(7)
            ->and(substr_count($matches[1], 'data-pain-point-title'))->toBe(7)
            ->and(substr_count($matches[1], 'data-pain-point-description'))->toBe(7);

        $positions = array_map(fn (string $situation): int|false => strpos($matches[1], $situation), $copy);
        $sortedPositions = $positions;
        sort($sortedPositions);

        expect($positions)->toBe($sortedPositions);
    }
});

test('landing hero and navigation transform into a floating sticky header on scroll', function () {
    $response = $this->get('/')->assertOk();
    $content = $response->getContent();
    $styles = file_get_contents(resource_path('css/app.css'));
    $script = file_get_contents(resource_path('js/animations/hero-shell-navigation.js'));

    $shellStart = strpos($content, 'data-hero-shell');
    $headerStart = strpos($content, 'data-public-site-header');
    $heroStart = strpos($content, 'data-hero>');
    $shellEnd = strpos($content, '</div>'.PHP_EOL.'    <main>', $heroStart);

    expect($shellStart)->not->toBeFalse()
        ->and($headerStart)->toBeLessThan($shellStart)
        ->and($heroStart)->toBeGreaterThan($shellStart)
        ->and($shellEnd)->toBeGreaterThan($heroStart)
        ->and($styles)->toContain('.public-site-header.is-floating')
        ->and($styles)->toContain('.public-site-header--hero {')
        ->and($styles)->toContain('position: fixed')
        ->and($styles)->toContain('padding-inline 180ms ease')
        ->and($styles)->toContain('width: var(--hero-shell-width)')
        ->and($script)->toContain("document.querySelector('[data-public-site-header].public-site-header--hero')")
        ->and($script)->toContain('TRANSITION_DISTANCE = 140')
        ->and($script)->toContain('MAX_RADIUS = 24')
        ->and($script)->toContain('window.requestAnimationFrame(update)')
        ->and($script)->toContain("header.classList.toggle('is-floating', progress >= 1)")
        ->and($script)->toContain("window.matchMedia('(prefers-reduced-motion: reduce)')")
        ->and($script)->toContain("window.matchMedia('(min-width: 768px)')")
        ->and($script)->toContain('desktopViewport.matches ? viewportWidth * 0.025 * progress : 0')
        ->and($script)->toContain('--hero-shell-padding-inline')
        ->and($script)->toContain('initialPadding + (floatingPadding - initialPadding) * progress')
        ->and($styles)->toContain('padding-inline: var(--hero-shell-padding-inline, clamp(22px, 5.5vw, 96px))');
});

test('landing page uses a white browser theme and centered mobile process and teacher interactions', function () {
    User::factory()->create([
        'name' => 'Alexandra Popescu',
        'is_teacher' => true,
        'teacher_active' => true,
    ]);

    $response = $this->get('/')->assertOk();
    $styles = file_get_contents(resource_path('css/app.css'));

    $response->assertSee('<meta name="theme-color" content="#ffffff">', false)
        ->assertSee('min-h-11 min-w-11', false)
        ->assertSee('h-[20.3125px] w-[20.3125px]', false)
        ->assertSee('flex items-center justify-between gap-3 p-6 text-white', false);

    expect($styles)->toContain('.how-step-card {')
        ->and($styles)->toContain('flex: 0 0 100%;')
        ->and($styles)->toContain('scroll-snap-align: center;')
        ->and($styles)->toContain('.teacher-card:hover .teacher-card__more')
        ->and($styles)->toContain('align-items: center;')
        ->and($styles)->toContain('@media (prefers-reduced-motion: reduce)')
        ->and($styles)->toContain('teacher-more-pulse 1.35s ease-in-out infinite')
        ->and($styles)->toContain('animation: none;');
});

test('landing page shows only active teachers by first name and never exposes their private details', function () {
    User::factory()->create([
        'name' => 'Alexandra Popescu',
        'email' => 'alexandra.private@example.com',
        'is_teacher' => true,
        'teacher_active' => true,
    ]);
    User::factory()->create([
        'name' => 'Inactive Teacher',
        'is_teacher' => true,
        'teacher_active' => false,
    ]);
    User::factory()->create([
        'name' => 'Regular Staff',
        'is_teacher' => false,
        'teacher_active' => true,
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('id="teachers"', false)
        ->assertSee('Alexandra')
        ->assertDontSee('Popescu')
        ->assertDontSee('alexandra.private@example.com')
        ->assertDontSee('Inactive Teacher')
        ->assertDontSee('Regular Staff');
});

test('teacher photos use relative storage URLs independent of the configured application host', function () {
    config(['app.url' => 'https://easyenglish.ro']);
    config(['filesystems.disks.public.url' => 'https://easyenglish.ro/storage']);
    Storage::fake('public');
    Storage::disk('public')->put('teachers/alexandra.jpg', 'photo-content');

    User::factory()->create([
        'name' => 'Alexandra Popescu',
        'is_teacher' => true,
        'teacher_active' => true,
        'teacher_photo_path' => 'teachers/alexandra.jpg',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('src="/storage/teachers/alexandra.jpg"', false)
        ->assertDontSee('src="https://easyenglish.ro/storage/teachers/alexandra.jpg"', false);
});

test('landing page explains real progress reporting and handles missing testimonials honestly', function () {
    $this->withSession(['locale' => 'en'])->get('/')
        ->assertOk()
        ->assertSee('id="testimonials"', false)
        ->assertSee(__('public.testimonials_empty'))
        ->assertSee('id="progress-reports"', false)
        ->assertSee(__('public.progress_text'))
        ->assertSee(__('public.progress_metric_confidence'))
        ->assertSee(__('public.progress_metric_fluency'))
        ->assertSee(__('public.progress_metric_vocabulary'))
        ->assertSee(__('public.progress_metric_pronunciation'))
        ->assertSee(__('public.progress_metric_grammar'))
        ->assertSee(__('public.progress_note'))
        ->assertDontSee('95% improvement');
});

test('teacher and progress sections are localized in Romanian', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Cunoaște-ți profesorii.')
        ->assertSee('EXPERIENȚELE CURSANȚILOR')
        ->assertSee('Progres pe care îl poți înțelege.')
        ->assertSee('Nu este un scor automat');
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

test('public site always renders Romanian and does not expose a locale switch endpoint', function () {
    $this->withSession(['locale' => 'en'])->get('/')
        ->assertOk()
        ->assertSee('<html lang="ro">', false)
        ->assertSee('De data asta, ai ceva de spus.')
        ->assertSee('Confidențialitate')
        ->assertDontSee('This time, you have something to say.')
        ->assertDontSee('name="locale"', false)
        ->assertDontSee('>RO<', false)
        ->assertDontSee('>EN<', false);

    $this->post('/language', ['locale' => 'en'])->assertNotFound();
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

test('public privacy and terms pages reuse the shared Romanian navigation and footer', function () {
    $this->withSession(['locale' => 'en'])->get('/privacy')
        ->assertOk()
        ->assertSee('<html lang="ro">', false)
        ->assertSee('Confidențialitate')
        ->assertSee('data-public-site-header', false)
        ->assertSee('data-public-site-footer', false)
        ->assertSee('images/auth-icon.svg', false)
        ->assertDontSee('name="locale"', false);

    $this->withSession(['locale' => 'en'])->get('/terms')
        ->assertOk()
        ->assertSee(__('public.footer_terms'))
        ->assertSee('data-public-site-header', false)
        ->assertSee('data-public-site-footer', false)
        ->assertSee('images/auth-icon.svg', false)
        ->assertDontSee('Terms and cancellation policy');
});

test('public legal pages identify the controller and explain privacy and consumer terms', function () {
    $this->get('/privacy')
        ->assertOk()
        ->assertSee('ROAD CENTRE SRL')
        ->assertSee('17808982')
        ->assertSee('J2005012930400')
        ->assertSee('office@edventure.ro')
        ->assertSee('Stripe')
        ->assertSee('ANSPDCP')
        ->assertSee('Cookie-uri');

    $this->get('/terms')
        ->assertOk()
        ->assertSee('ROAD CENTRE SRL')
        ->assertSee('cel puțin 24 de ore')
        ->assertSee('cel puțin 12 ore')
        ->assertSee('39 RON')
        ->assertSee('dreptul de retragere');
});

test('public booking page reuses the shared public navigation', function () {
    $this->get('/book?type=intro')
        ->assertOk()
        ->assertSee('data-public-site-header', false)
        ->assertSee('images/logo-white.svg', false)
        ->assertSee('images/auth-icon.svg', false)
        ->assertSee('data-public-site-footer', false);
});

test('shared footer is centered in two rows and is reused on dashboard and authentication pages', function () {
    config()->set('easyenglish.contact_email', 'contact@easyenglish.ro');

    $response = $this->get('/')->assertOk()->assertSee('data-public-site-footer', false);
    $content = $response->getContent();
    $footerStart = strpos($content, '<footer');
    $footerEnd = strpos($content, '</footer>', $footerStart);
    expect($footerStart)->not->toBeFalse()
        ->and($footerEnd)->not->toBeFalse();

    $footer = substr($content, $footerStart, $footerEnd - $footerStart);
    expect($footer)
        ->toContain('flex-col items-center')
        ->toContain('text-center')
        ->toContain('justify-center');

    $attribution = strpos($footer, __('public.footer_line'));
    $privacy = strpos($footer, __('public.footer_privacy'));
    $terms = strpos($footer, __('public.footer_terms'));
    $contact = strpos($footer, __('public.footer_contact'));
    expect($attribution)->not->toBeFalse()
        ->and($privacy)->toBeGreaterThan($attribution)
        ->and($terms)->toBeGreaterThan($privacy)
        ->and($contact)->toBeGreaterThan($terms)
        ->and($footer)->toContain('href="'.route('contact').'"');

    $customer = User::factory()->create();
    $this->actingAs($customer)->withSession(['locale' => 'en'])->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-public-site-footer', false)
        ->assertDontSee('name="locale"', false);

    auth()->logout();
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('data-public-site-footer', false)
        ->assertDontSee('name="locale"', false);
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
    ])->assertRedirect(route('dashboard'))
        ->assertSessionHas('status', __('public.booking_confirmed_dashboard'));

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

    $this->withSession(['locale' => 'en'])->get($signedFormUrl.'&slot='.urlencode('2026-10-27T09:30:00+02:00'))->assertOk()->assertSee(__('public.booking_bundle_title'));

    $signedSubmitUrl = URL::signedRoute('booking.submit', ['type' => 'bundle', 'entitlement' => $entitlement->id], now()->addMinutes(30));
    $this->post($signedSubmitUrl, [
        'type' => 'bundle', 'entitlement' => $entitlement->id, 'starts_at' => '2026-10-27T09:00:00+02:00',
        'name' => 'Test Learner', 'email' => 'learner@example.com', 'phone' => '+40722123456', 'whatsapp_service_consent' => '1',
        'english_level' => 'B1', 'session_goal' => 'Work conversations', 'accepted_policy' => '1',
    ])->assertRedirect(route('dashboard'))
        ->assertSessionHas('status', __('public.booking_confirmed_dashboard'));

    expect(Booking::query()->where('status', 'confirmed')->count())->toBe(1)
        ->and($entitlement->fresh()->credits_remaining)->toBe(3);
    $response = $this->get(Booking::query()->firstOrFail()->public_reference ? URL::signedRoute('booking.confirmed', ['reference' => Booking::query()->firstOrFail()->public_reference], null, false) : '/');
    $response->assertOk()->assertSee(__('public.booking_confirmed_title'));
});

test('verified free booking redirects to dashboard and assembles the same OpenWA link for customer and assigned teacher', function () {
    $teacher = User::factory()->create([
        'email' => 'journey-teacher@example.com',
        'is_teacher' => true,
        'teacher_active' => true,
    ]);
    TeacherCalendar::query()->create([
        'user_id' => $teacher->id,
        'calendar_id' => 'primary',
        'calendar_email' => $teacher->email,
        'access_token' => 'encrypted-access-token',
        'refresh_token' => 'encrypted-refresh-token',
        'token_expires_at' => now()->addHour(),
        'status' => 'connected',
        'last_synced_at' => now(),
    ]);
    AvailabilityRule::query()->create([
        'user_id' => $teacher->id,
        'weekday' => 2,
        'starts_at' => '09:00',
        'ends_at' => '11:00',
        'is_active' => true,
    ]);
    $slot = '2026-10-27T09:00:00+02:00';
    $provider = new FakeCallEventProvider;
    config([
        'easyenglish.teacher_availability_enabled' => true,
        'services.openwa.enabled' => true,
        'services.openwa.apply_to_paid_sessions' => false,
        'services.openwa.rollout_stage' => 'general',
        'services.openwa.rollout_customer_emails' => [],
        'services.openwa.base_url' => 'https://openwa.test',
        'services.openwa.service_token' => str_repeat('s', 48),
        'services.openwa.connect_timeout' => 5,
        'services.openwa.timeout' => 30,
        'services.openwa.max_attempts' => 5,
        'services.openwa.retry_delays_seconds' => [60, 300, 900, 3600, 14400],
        'services.contact_verification.mode' => 'email_and_whatsapp',
        'services.whatsapp.verification_enabled' => true,
        'services.whatsapp.access_token' => 'test-token',
        'services.whatsapp.phone_number_id' => 'test-phone-id',
        'services.whatsapp.app_secret' => 'meta-test-secret',
        'services.whatsapp.verify_token' => 'verify-test-token',
        'services.whatsapp.openwa.enabled' => true,
        'services.whatsapp.openwa.base_url' => 'https://openwa.example.com',
        'services.whatsapp.openwa.service_token' => str_repeat('s', 48),
        'services.whatsapp.openwa.callback_secret' => str_repeat('c', 48),
        'services.whatsapp.templates.contact_verification' => 'easyenglish_activation',
        'mail.default' => 'array',
    ]);
    app()->forgetInstance(OpenWaConfig::class);
    app()->forgetInstance(BookingCallEventService::class);
    app()->instance(CallEventProvider::class, $provider);
    Queue::fake();

    $whatsappGateway = new FakeWhatsAppGateway;
    app()->instance(WhatsAppNotificationGateway::class, $whatsappGateway);
    app()->instance(WhatsAppVerificationGateway::class, $whatsappGateway);
    app()->instance(FakeWhatsAppGateway::class, $whatsappGateway);
    $this->withSession(['locale' => 'en'])->postJson(route('booking.register'), [
        'name' => 'Journey Customer',
        'email' => 'journey-customer@example.com',
        'phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Prepare for a work presentation',
        'session_notes' => 'Practice a clear opening.',
        'password' => 'StrongPassword123!',
        'password_confirmation' => 'StrongPassword123!',
        'starts_at' => $slot,
        'accepted_policy' => '1',
        'whatsapp_service_consent' => '1',
        'locale' => 'en',
    ])->assertCreated()->assertJsonPath('next_step', 'contact_verification');

    $customer = User::query()->where('email', 'journey-customer@example.com')->firstOrFail();
    $this->postJson(route('verification.start'), [
        'return_to' => 'booking', 'type' => 'intro', 'email' => $customer->email, 'phone' => $customer->phone,
        'starts_at' => $slot, 'english_level' => 'B1', 'session_goal' => 'Prepare for a work presentation',
        'notes' => 'Practice a clear opening.', 'accepted_policy' => '1', 'whatsapp_service_consent' => '1', 'locale' => 'en',
    ])->assertOk()->assertJsonPath('status', 'verification_required');
    $challenge = ContactVerificationChallenge::query()->where('customer_email', $customer->email)->firstOrFail();
    $challenge->update(['email_code_hash' => hash_hmac('sha256', '654321', (string) config('app.key'))]);
    $phoneCode = $whatsappGateway->sent[0]['components'][0]['parameters'][0]['text'];
    $this->postJson(route('verification.email-code.confirm'), [
        'verification_id' => $challenge->id, 'email_code' => '654321', 'phone' => $customer->phone,
    ])->assertOk()->assertJsonPath('status', 'verification_required');
    $this->postJson(route('verification.phone.confirm'), [
        'verification_id' => $challenge->id, 'phone_code' => $phoneCode, 'phone' => $customer->phone,
    ])->assertOk()->assertJsonPath('status', 'eligible')->assertJsonPath('eligibility.eligible', true);
    expect($customer->fresh()->email_verified_at)->not->toBeNull()
        ->and($customer->fresh()->phone_verified_at)->not->toBeNull();
    config(['services.whatsapp.verification_enabled' => false]);

    $this->actingAs($customer)->post(route('booking.submit'), [
        'type' => 'intro',
        'starts_at' => $slot,
        'name' => $customer->name,
        'email' => $customer->email,
        'phone' => $customer->phone,
        'english_level' => 'B1',
        'session_goal' => 'Prepare for a work presentation',
        'accepted_policy' => '1',
    ])->assertRedirect(route('dashboard'))
        ->assertSessionHas('status', __('public.booking_confirmed_dashboard'));

    $booking = Booking::query()->where('customer_email', $customer->email)->firstOrFail();
    expect($booking->status)->toBe('confirmed')
        ->and($booking->teacher_id)->toBe($teacher->id)
        ->and(FreeSessionClaim::query()->where('customer_email', $customer->email)->value('status'))->toBe('used');

    $event = app(BookingCallEventService::class)->createForBooking($booking->id, 1);
    $customerDelivery = NotificationDelivery::query()->where('booking_call_event_id', $event->id)->where('event', 'customer_call_link_ready')->firstOrFail();
    $teacherDelivery = NotificationDelivery::query()->where('booking_call_event_id', $event->id)->where('event', 'teacher_call_link_ready')->firstOrFail();
    $customerMessage = app(NotificationMessageFactory::class)->make($customerDelivery);
    $teacherMessage = app(NotificationMessageFactory::class)->make($teacherDelivery);

    expect($event->status)->toBe('scheduled')
        ->and($provider->createRequests)->toHaveCount(1)
        ->and($customerDelivery->recipient_type)->toBe('customer')
        ->and($teacherDelivery->recipient_user_id)->toBe($teacher->id)
        ->and($customerMessage['ctaUrl'])->toBe($event->join_url)
        ->and(collect($teacherMessage['details'])->firstWhere('label', 'WhatsApp call link')['value'])->toBe($event->join_url);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee(__('public.dashboard_call_link_ready'))
        ->assertSee('href="'.route('booking.call-join', $booking->public_reference).'"', false)
        ->assertDontSee($event->join_url);
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
        ->assertSee('data-public-site-header', false)
        ->assertSee('images/auth-icon.svg', false)
        ->assertSee('156 RON')
        ->assertSee('trei luni calendaristice')
        ->assertSee('Sesiunea introductivă gratuită nu face parte din acest pachet')
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

test('public teacher cards open accessible profile dialogs without exposing private contact details', function () {
    User::factory()->create([
        'name' => 'Morgan Lee',
        'email' => 'morgan.private@example.com',
        'teacher_bio' => 'I help adults speak with confidence at work and while travelling.',
        'is_teacher' => true,
        'teacher_active' => true,
    ]);

    $this->withSession(['locale' => 'en'])->get('/')
        ->assertOk()
        ->assertSee('teacher-card', false)
        ->assertSee('Morgan')
        ->assertSee('data-teacher-open', false)
        ->assertSee('data-teacher-dialog', false)
        ->assertSee('teacher-dialog__background', false)
        ->assertSee('teacher-dialog__photo', false)
        ->assertSee('teacher-dialog__biography', false)
        ->assertSee('I help adults speak with confidence at work and while travelling.')
        ->assertSee(__('public.teacher_more', ['name' => 'Morgan']))
        ->assertDontSee('English conversation teacher')
        ->assertDontSee('Profesor de conversație în limba engleză')
        ->assertDontSee('Book a session')
        ->assertDontSee('morgan.private@example.com');

    $styles = file_get_contents(resource_path('css/app.css'));
    $scripts = file_get_contents(resource_path('js/app.js'));

    expect($styles)->toContain('filter: blur(24px)')
        ->and($styles)->toContain('border-radius: 50%')
        ->and($scripts)->toContain('prefers-reduced-motion: reduce')
        ->and($scripts)->toContain('duration: 0.48');
});

test('mobile hero photos retain retina resolution', function () {
    foreach (['student-mobile-hero.webp', 'teacher-mobile-hero.webp'] as $image) {
        $dimensions = getimagesize(public_path("images/{$image}"));

        expect($dimensions)->not->toBeFalse()
            ->and($dimensions[0])->toBeGreaterThanOrEqual(800)
            ->and($dimensions[1])->toBeGreaterThanOrEqual(1600);
    }
});

test('desktop hero taglines share one frosted-glass container while the mobile rotator remains separate', function () {
    $response = $this->get('/')->assertOk();
    $content = $response->getContent();
    $styles = file_get_contents(resource_path('css/app.css'));

    expect(substr_count($content, 'data-hero-trust>'))->toBe(1)
        ->and(substr_count($content, 'data-hero-trust-item'))->toBe(3)
        ->and($styles)->toContain('@media (min-width: 768px) {')
        ->and($styles)->toContain('[data-hero-trust] {')
        ->and($styles)->toContain('border: 1px solid var(--hero-glass-border)')
        ->and($styles)->toContain('border-radius: 100px')
        ->and($styles)->toContain('background: var(--hero-glass-surface)')
        ->and($styles)->toContain('backdrop-filter: blur(var(--hero-glass-blur))')
        ->and($styles)->toContain('@media (max-width: 767px) {');
});

test('desktop hero chat bubbles reuse the frosted-glass surface without changing the conversation hooks', function () {
    $response = $this->get('/')->assertOk();
    $styles = file_get_contents(resource_path('css/app.css'));

    expect(substr_count($response->getContent(), 'class="hero-conversation__bubble"'))->toBe(14)
        ->and($styles)->toContain('--hero-glass-surface: rgb(255 255 255 / 82%)')
        ->and($styles)->toContain('--hero-glass-border: rgb(255 255 255 / 82%)')
        ->and($styles)->toContain('--hero-glass-shadow: 0 8px 28px rgb(20 35 75 / 14%)')
        ->and($styles)->toContain('--hero-glass-blur: 10px')
        ->and($styles)->toContain('background: var(--hero-glass-surface)')
        ->and($styles)->toContain('backdrop-filter: blur(var(--hero-glass-blur))')
        ->and($styles)->toContain('.hero-conversation__side--learner .hero-conversation__bubble');
});

test('mobile hero contains the complete fourteen-message conversation', function () {
    $response = $this->get('/')->assertOk();

    expect(substr_count($response->getContent(), 'hero-conversation-mobile__message--teacher'))->toBe(7)
        ->and(substr_count($response->getContent(), 'hero-conversation-mobile__message--learner'))->toBe(7);
});

test('registration submits asynchronously and returns the authenticated contact-verification step without redirecting', function () {
    $slot = '2026-10-27T09:00:00+03:00';
    $beforeSessionId = session()->getId();

    $response = $this->withSession(['locale' => 'ro'])->postJson(route('booking.register'), [
        'name' => 'Async Learner',
        'email' => 'async-learner@example.com',
        'phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Speak more confidently at work',
        'session_notes' => 'Prepare for customer calls.',
        'password' => 'StrongPassword123!',
        'password_confirmation' => 'StrongPassword123!',
        'starts_at' => $slot,
        'accepted_policy' => '1',
        'whatsapp_service_consent' => '1',
        'locale' => 'en',
    ]);

    $response->assertCreated()
        ->assertJsonPath('status', 'authenticated')
        ->assertJsonPath('next_step', 'contact_verification')
        ->assertJsonPath('slot', $slot)
        ->assertJsonPath('locale', 'ro')
        ->assertJsonMissingPath('redirect_url');

    $user = User::query()->where('email', 'async-learner@example.com')->firstOrFail();
    expect(auth()->id())->toBe($user->id)
        ->and(session('locale'))->toBe('ro')
        ->and(session()->getId())->not->toBe($beforeSessionId)
        ->and($response->json('html'))->toContain('name="_token"')
        ->and($response->json('html'))->toContain('data-booking-starts-at')
        ->and($response->json('html'))->toContain($slot)
        ->and($response->json('html'))->toContain(__('public.verification_title'))
        ->and($response->json('html'))->toContain('name="accepted_policy"')
        ->and($response->json('html'))->not->toContain('name="whatsapp_service_consent"');

    expect(WhatsAppContact::query()->where('phone', '+40722123456')->first()?->canReceiveServiceMessages())->toBeTrue();
});

test('contact verification preserves policy acceptance from asynchronous registration when checkbox data is omitted', function () {
    $user = User::factory()->create([
        'email' => 'policy-session@example.com',
        'phone' => '+40722123456',
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)->withSession([
        'locale' => 'ro',
        'contact_verification_form_data' => [
            'accepted_policy' => '1',
        ],
    ])->postJson(route('verification.start'), [
        'return_to' => 'booking',
        'type' => 'intro',
        'email' => $user->email,
        'phone' => $user->phone,
        'starts_at' => '2026-10-27T09:00:00+02:00',
        'english_level' => 'B1',
        'session_goal' => 'Work conversations',
    ]);

    $response->assertOk()
        ->assertJsonPath('eligibility.eligible', true)
        ->assertJsonPath('next_step', 'eligible');
});

test('contact verification returns a localized policy acceptance error when consent was never recorded', function () {
    $user = User::factory()->create([
        'email' => 'policy-required@example.com',
        'phone' => '+40722123456',
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);

    $this->actingAs($user)->withSession(['locale' => 'ro'])
        ->postJson(route('verification.start'), [
            'return_to' => 'booking',
            'type' => 'intro',
            'email' => $user->email,
            'phone' => $user->phone,
            'starts_at' => '2026-10-27T09:00:00+02:00',
            'english_level' => 'B1',
            'session_goal' => 'Work conversations',
        ])->assertUnprocessable()
        ->assertJsonPath('errors.accepted_policy.0', __('public.policy_accept_required'));
});

test('existing account login submits asynchronously and advances directly to booking when contacts are already verified', function () {
    $user = User::factory()->create([
        'email' => 'async-existing@example.com',
        'phone' => '+40722123456',
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);
    $slot = '2026-10-27T09:00:00+03:00';
    $beforeSessionId = session()->getId();

    $response = $this->withSession(['locale' => 'en'])->postJson(route('booking.login'), [
        'email' => $user->email,
        'password' => 'password',
        'starts_at' => $slot,
        'locale' => 'en',
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'authenticated')
        ->assertJsonPath('next_step', 'eligible')
        ->assertJsonPath('slot', $slot)
        ->assertJsonMissingPath('redirect_url');

    expect(auth()->id())->toBe($user->id)
        ->and(session()->getId())->not->toBe($beforeSessionId)
        ->and($response->json('html'))->toContain('name="_token"')
        ->and($response->json('html'))->toContain('data-booking-starts-at')
        ->and($response->json('html'))->toContain($slot)
        ->and($response->json('html'))->toContain('name="whatsapp_service_consent"');
});

test('asynchronous login reports invalid credentials as an accessible authentication error', function () {
    $user = User::factory()->create(['email' => 'wrong-password@example.com']);

    $this->postJson(route('booking.login'), [
        'email' => $user->email,
        'password' => 'incorrect-password',
        'starts_at' => '2026-10-27T09:00:00+03:00',
        'locale' => 'en',
    ])->assertUnprocessable()
        ->assertJsonPath('status', 'authentication_failed')
        ->assertJsonStructure(['errors' => ['email']]);

    $this->assertGuest();
});

test('asynchronous registration returns field validation errors without creating an account', function () {
    $this->postJson(route('booking.register'), [
        'name' => 'Invalid Learner',
        'email' => 'not-an-email',
        'phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Speak more confidently',
        'password' => 'StrongPassword123!',
        'password_confirmation' => 'StrongPassword123!',
        'starts_at' => '2026-10-27T09:00:00+03:00',
        'accepted_policy' => '1',
        'whatsapp_service_consent' => '1',
        'locale' => 'en',
    ])->assertUnprocessable()->assertJsonValidationErrors(['email']);

    expect(User::query()->where('email', 'not-an-email')->exists())->toBeFalse();
});

test('duplicate asynchronous registration cannot create a second account', function () {
    $payload = [
        'name' => 'One Account',
        'email' => 'one-account@example.com',
        'phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Speak more confidently',
        'password' => 'StrongPassword123!',
        'password_confirmation' => 'StrongPassword123!',
        'starts_at' => '2026-10-27T09:00:00+03:00',
        'accepted_policy' => '1',
        'whatsapp_service_consent' => '1',
        'locale' => 'en',
    ];

    $this->postJson(route('booking.register'), $payload)->assertCreated();
    Auth::logout();
    $this->flushSession();

    $this->postJson(route('booking.register'), $payload)->assertUnprocessable()->assertJsonValidationErrors(['email']);

    expect(User::query()->where('email', 'one-account@example.com')->count())->toBe(1);
});

test('asynchronous login preserves the Fortify two-factor challenge and selected slot', function () {
    $user = User::factory()->withTwoFactor()->create(['email' => 'async-two-factor@example.com']);
    $slot = '2026-10-27T09:00:00+03:00';

    $this->withSession(['locale' => 'en'])->postJson(route('booking.login'), [
        'email' => $user->email,
        'password' => 'password',
        'starts_at' => $slot,
        'locale' => 'en',
    ])->assertStatus(409)
        ->assertJsonPath('status', 'two_factor_required')
        ->assertJsonPath('redirect_url', route('login'));

    expect(auth()->check())->toBeFalse()
        ->and(session('url.intended'))->toBe(route('home', ['slot' => $slot]))
        ->and(session('locale'))->toBe('en');
});

test('booking submission rejects a slot that is no longer available on the server', function () {
    $user = User::factory()->create([
        'email' => 'stale-slot@example.com',
        'phone' => '+40722123456',
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);
    $this->actingAs($user);

    $this->from('/')->post(route('booking.submit'), [
        'type' => 'intro',
        'starts_at' => '2026-10-27T09:00:00+03:00',
        'name' => $user->name,
        'email' => $user->email,
        'phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Speak more confidently',
        'accepted_policy' => '1',
        'whatsapp_service_consent' => '1',
    ])->assertRedirect('/')
        ->assertSessionHasErrors(['starts_at']);

    expect(Booking::query()->count())->toBe(0);
});

test('final booking submission preserves policy acceptance recorded during registration', function () {
    $user = User::factory()->create([
        'email' => 'policy-final-submit@example.com',
        'phone' => '+40722123456',
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);

    $this->actingAs($user)->withSession([
        'locale' => 'en',
        'contact_verification_form_data' => [
            'accepted_policy' => '1',
        ],
    ])->postJson(route('booking.submit'), [
        'type' => 'intro',
        'starts_at' => '2026-10-27T09:00:00+03:00',
        'name' => $user->name,
        'email' => $user->email,
        'phone' => $user->phone,
        'english_level' => 'B1',
        'session_goal' => 'Speak more confidently at work',
        'whatsapp_service_consent' => '1',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['starts_at'])
        ->assertJsonMissingValidationErrors(['accepted_policy']);

    expect(Booking::query()->count())->toBe(0);
});

test('final free booking still requires WhatsApp consent when no prior consent exists', function () {
    $user = User::factory()->create([
        'email' => 'whatsapp-consent-required@example.com',
        'phone' => '+40722123456',
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);

    $this->actingAs($user)->postJson(route('booking.submit'), [
        'type' => 'intro',
        'starts_at' => '2026-10-27T09:00:00+03:00',
        'name' => $user->name,
        'email' => $user->email,
        'phone' => $user->phone,
        'english_level' => 'B1',
        'session_goal' => 'Speak more confidently at work',
        'accepted_policy' => '1',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['whatsapp_service_consent']);

    expect(Booking::query()->count())->toBe(0);
});
