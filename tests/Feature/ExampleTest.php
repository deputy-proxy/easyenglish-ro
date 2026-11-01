<?php

test('returns a successful response', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee('hero-teacher.png');
    $response->assertSee('hero-student.png');
    $response->assertSee(app()->getLocale() === 'ro' ? 'Programează prima sesiune gratuită' : 'Book your first free session');
    $response->assertSee(app()->getLocale() === 'ro' ? 'Conversație pe WhatsApp' : 'Conversation on WhatsApp');
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

test('public FAQ section mirrors the progress card layout with questions on the left', function () {
    $response = $this->get(route('home'));
    $content = $response->getContent();

    $response->assertOk();
    $response->assertSee('id="faq"', false);
    $response->assertSee('rounded-[22px] border border-line bg-white p-4 shadow-[0_16px_48px_rgba(20,35,75,0.06)] sm:p-6', false);
    $response->assertSee(__('public.faq_q1'));
    $response->assertSee(__('public.faq_a4'));

    expect(strpos($content, __('public.faq_q1')))
        ->toBeLessThan(strpos($content, __('public.faq_eyebrow')));
});
