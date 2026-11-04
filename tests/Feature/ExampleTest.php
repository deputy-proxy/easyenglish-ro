<?php

use App\Models\Faq;

test('returns a successful response', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee('Autentificare');
    $response->assertSee('images/easyenglish-symbol-blue.svg');
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

test('the four-step booking journey explains the free introduction and optional continuation in both locales', function () {
    $expectedCopy = [
        'ro' => [
            'Alege o oră',
            'Vezi intervalele disponibile pentru întreaga săptămână și alege o sesiune de 15 minute.',
            'Spune-ne ce vrei să îmbunătățești',
            'Împărtășește nivelul tău de engleză, obiectivele și situațiile în care o folosești.',
            'Începe cu o conversație gratuită',
            'Discută pe WhatsApp cu un profesor EasyEnglish într-o sesiune introductivă gratuită de 15 minute.',
            'Continuă în ritmul tău',
            'Dacă vrei să continui, alegi un pachet și programezi următoarele conversații. Profesorul le pregătește pentru nivelul, interesele și obiectivele tale.',
        ],
        'en' => [
            'Choose a time',
            'Browse the week’s available times and choose a 15-minute slot that works for you.',
            'Tell us what you want to improve',
            'Share your English level, goals and the situations where you use English.',
            'Start with a free conversation',
            'Meet an EasyEnglish teacher on WhatsApp for your free 15-minute introductory conversation.',
            'Continue at your own pace',
            'If you’d like to continue, choose a bundle and schedule your next conversations. Your teacher prepares each one around your level, interests and goals.',
        ],
    ];

    $obsoleteCopy = [
        'ro' => ['Începe conversația', 'Profesorul pregătește. Tu exersezi.'],
        'en' => ['Start talking', 'Your teacher prepares. You practise.'],
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

        expect(preg_match('/<section[^>]*id="how"[^>]*>(.*?)<\/section>/s', $content, $matches))->toBe(1)
            ->and(substr_count($matches[1], '<article'))->toBe(4);
    }
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
