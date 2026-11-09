<?php

use App\Models\Faq;
use App\Support\VersionedAsset;

test('returns a successful response', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee('Autentificare');
    $response->assertSee('images/auth-button.svg');
    $response->assertSee('/admin/login');
    $response->assertSee('public-site-header', false);
    $response->assertSee('sticky inset-x-0 top-0 z-50', false);
    $response->assertSee('data-public-site-header', false);
    $response->assertSee('public-hero-shell', false);
    $response->assertDontSee('border-b border-line/70 bg-white/95', false);
    $response->assertDontSee('shadow-[0_1px_12px_rgba(20,35,75,0.06)]', false);
    $response->assertSee('hero-teacher.png');
    $response->assertSee('hero-student.png');
    $response->assertSee(app()->getLocale() === 'ro' ? 'Programează prima sesiune gratuită' : 'Book your first free session');
    $response->assertSee(__('public.trust_flexibility'));
    $response->assertSee(__('public.trust_teachers'));
    $response->assertSee(__('public.trust_speaking'));
    $response->assertSee('data-weekgrid-mobile');
    $response->assertSee('data-weekgrid aria-live="polite"', false);
    $response->assertSee(__('public.availability_more_times'));
    $response->assertSee('rounded-3xl bg-primary px-[21px] py-3.5 font-bold text-white transition hover:bg-primary-hover md:rounded-full', false);
});

test('mobile booking CTA reuses the free booking anchor and localized copy', function () {
    foreach (['ro' => 'Programează gratuit', 'en' => 'Book for free'] as $locale => $label) {
        $response = $this->withSession(['locale' => $locale])->get(route('home'));

        $response->assertOk();
        $response->assertSee('data-mobile-booking-cta', false);
        $response->assertSee('href="#availability"', false);
        $response->assertSee('hidden', false);
        $response->assertSee('inert', false);
        $response->assertSee('aria-hidden="true"', false);
        $response->assertSee($label);
    }
});

test('mobile public navigation reserves its own row while desktop navigation overlays the hero', function () {
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($styles)
        ->not->toContain('margin-bottom: calc(-76px - env(safe-area-inset-top, 0px))')
        ->toContain('@media (min-width: 768px)')
        ->toContain('margin-bottom: calc(-88px - env(safe-area-inset-top, 0px))')
        ->toContain('scroll-padding-top: calc(76px + env(safe-area-inset-top, 0px))')
        ->toContain('scroll-padding-top: calc(88px + env(safe-area-inset-top, 0px))');
});

test('mobile booking CTA respects safe areas and reduced-motion preferences', function () {
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($styles)
        ->toContain('env(safe-area-inset-bottom, 0px)')
        ->toContain('env(safe-area-inset-right, 0px)')
        ->toContain('@media (prefers-reduced-motion: reduce)')
        ->toContain('[data-mobile-booking-cta][hidden]');
});

test('public progress section displays an illustrative radar chart instead of metric cards', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee('public-progress-chart-title');
    $response->assertSee(__('public.progress_chart_sample'));
    $response->assertSee(__('public.progress_chart_baseline'));
    $response->assertSee(__('public.progress_chart_after_five'));
    $response->assertSee(__('public.progress_chart_after_ten'));
    $response->assertSee(__('public.progress_chart_aria'));
    $response->assertDontSee(__('public.progress_metric_confidence_text'));
});

test('the four-step learner journey renders the approved process and responsive card track in both locales', function () {
    $expectedCopy = [
        'ro' => [
            'Alege intervalul',
            'Alegi intervalul și durata sesiunii care ți se potrivesc. Fără program fix sau angajamente pe termen lung.',
            'Primeste invitatia',
            'Primești linkul pentru apelul tău pe WhatsApp și îl folosești pentru a intra în sesiune la ora stabilită.',
            'Incepe sa vorbesti',
            'Discuți cu profesorul tău și exersezi engleza prin conversații relaxate, individuale, despre situații reale.',
            'Urmareste progresul',
            'După sesiuni, profesorul îți poate oferi feedback personalizat, poate evidenția ce ai făcut bine și îți poate recomanda ce să exersezi în continuare.',
        ],
        'en' => [
            'Choose your time',
            'Pick the time and session duration that work for you. No fixed schedules or long-term commitments.',
            'Get your call link',
            'Receive your personal WhatsApp call link and use it to join when your session starts.',
            'Start speaking',
            'Meet your teacher and practise real-life English through a relaxed, one-to-one conversation.',
            'Track your progress',
            'After your sessions, your teacher can share personalised feedback, highlight what you did well, and suggest what to practise next.',
        ],
    ];

    $obsoleteCopy = [
        'ro' => ['Spune-ne ce vrei să îmbunătățești', 'Începe cu o conversație gratuită', 'Continuă în ritmul tău'],
        'en' => ['Tell us what you want to improve', 'Start with a free conversation', 'Continue at your own pace'],
    ];

    foreach ($expectedCopy as $locale => $copy) {
        $response = $this->withSession(['locale' => $locale])->get(route('home'));
        $content = $response->getContent();

        $response->assertOk();

        foreach ($copy as $line) {
            $response->assertSee($line);
        }

        foreach ($obsoleteCopy[$locale] as $line) {
            $response->assertDontSee($line);
        }

        $response
            ->assertSee('how-steps-track', false)
            ->assertSee('data-how-steps', false)
            ->assertSee('role="region"', false)
            ->assertSee('tabindex="0"', false)
            ->assertSeeInOrder([
                VersionedAsset::url('images/step-1.png'),
                VersionedAsset::url('images/step-2.png'),
                VersionedAsset::url('images/step-3.png'),
                VersionedAsset::url('images/step-4.png'),
            ], false);

        expect(preg_match('/<section[^>]*id="how"[^>]*>(.*?)<\\/section>/s', $content, $matches))->toBe(1)
            ->and(substr_count($matches[1], '<article'))->toBe(4);

        expect($matches[1])
            ->toContain('aspect-[4/3] w-full shrink-0')
            ->toContain('mt-4 flex-1 px-5 pb-5 sm:px-6 sm:pb-6')
            ->not->toContain('p-5 sm:p-6');
    }

    $styles = file_get_contents(resource_path('css/app.css'));

    expect($styles)
        ->toContain('.how-steps-track')
        ->toContain('scroll-snap-type: x mandatory')
        ->toContain('flex: 0 0 100%')
        ->toContain('scroll-snap-align: center;')
        ->toContain('@media (min-width: 1024px)')
        ->toContain('grid-template-columns: repeat(4, minmax(0, 1fr))');
});

test('the single public FAQ section keeps the bottom layout and renders backend-managed questions', function () {
    Faq::factory()->published()->create([
        'question' => 'Another backend-managed question?',
        'answer' => 'Another backend-managed answer.',
        'locale' => app()->getLocale(),
        'sort_order' => 5,
    ]);

    Faq::factory()->published()->create([
        'question' => 'Backend-managed question?',
        'answer' => 'Backend-managed answer.',
        'locale' => app()->getLocale(),
        'sort_order' => 10,
    ]);

    $response = $this->withSession(['locale' => 'en'])->get(route('home'));
    $content = $response->getContent();

    $response->assertOk();
    $response->assertSee('id="faq"', false);
    $response->assertDontSee('id="faqs"', false);
    $response->assertSee('Backend-managed question?');
    $response->assertSee('Backend-managed answer.');
    $response->assertSee('name="faq"', false);
    expect(substr_count($content, 'name="faq"'))->toBe(2);
    $response->assertSee(__('public.faq_text'));
    $response->assertSee('rounded-[22px] bg-white p-4 shadow-[0_1px_2px_rgba(0,0,0,0.07),0_2px_4px_rgba(0,0,0,0.07),0_4px_8px_rgba(0,0,0,0.07),0_8px_16px_rgba(0,0,0,0.07),0_16px_32px_rgba(0,0,0,0.07),0_32px_64px_rgba(0,0,0,0.07)] sm:p-6', false);
    $response->assertDontSee(__('public.faq_q1'));

    expect(substr_count($content, 'id="faq"'))->toBe(1)
        ->and(strpos($content, __('public.faq_eyebrow')))
        ->toBeLessThan(strpos($content, 'Backend-managed question?'))
        ->and(strpos($content, __('public.faq_text')))
        ->toBeLessThan(strpos($content, 'Backend-managed question?'));
});

test('FAQ rows center closed question headers and reserve a separate answer row when expanded', function () {
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($styles)
        ->toContain('grid-template-rows: minmax(0, 1fr);')
        ->toContain('grid-template-rows: auto minmax(0, 1fr);')
        ->toContain('align-items: center;');
});

test('public booking, bundle, progress, and FAQ containers share the borderless layered shadow', function () {
    Faq::factory()->published()->create(['locale' => 'ro']);

    $response = $this->get(route('home'));
    $content = $response->getContent();
    $shadow = 'shadow-[0_1px_2px_rgba(0,0,0,0.07),0_2px_4px_rgba(0,0,0,0.07),0_4px_8px_rgba(0,0,0,0.07),0_8px_16px_rgba(0,0,0,0.07),0_16px_32px_rgba(0,0,0,0.07),0_32px_64px_rgba(0,0,0,0.07)]';

    $response->assertOk();
    expect(substr_count($content, $shadow))->toBeGreaterThanOrEqual(4);
    $response->assertSee('data-availability');
    $response->assertSee('aria-labelledby="public-progress-chart-title"', false);
    $response->assertSee('aria-label="'.__('public.faq_title').'"', false);
    $response->assertDontSee('rounded-[22px] border border-primary-border bg-white p-3');
    $response->assertDontSee('rounded-[22px] border border-line bg-white p-4');
});
