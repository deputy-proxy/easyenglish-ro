<?php

it('renders optional cookie consent controls without loading Google tags before consent', function (): void {
    $this->get('/contact')
        ->assertOk()
        ->assertSee('data-cookie-consent', false)
        ->assertSee('data-consent-accept-all', false)
        ->assertSee('data-consent-reject', false)
        ->assertSee('data-consent-analytics', false)
        ->assertSee('data-consent-advertising', false)
        ->assertSee('data-gtm-id=""', false)
        ->assertDontSee('googletagmanager.com/gtm.js');
});

it('exposes a configured GTM container ID without loading it before visitor consent', function (): void {
    config(['services.analytics.gtm_id' => 'GTM-TEST123']);

    $this->get('/contact')
        ->assertOk()
        ->assertSee('data-gtm-id="GTM-TEST123"', false)
        ->assertDontSee('googletagmanager.com/gtm.js');
});

it('renders a successful introductory booking event without exposing booking contact data', function (): void {
    $this->withSession([
        'analytics_booking_completed' => ['lead_type' => 'free_introductory'],
    ])->get('/contact')
        ->assertOk()
        ->assertSee('data-analytics-event="booking_completed"', false)
        ->assertSee('free_introductory')
        ->assertDontSee('customer_email');
});

it('renders successful booking submission tracking separately from lead conversion', function (): void {
    $this->withSession([
        'analytics_booking_details_submitted' => ['booking_type' => 'bundle_session'],
    ])->get('/contact')
        ->assertOk()
        ->assertSee('data-analytics-event="booking_details_submit"', false)
        ->assertSee('bundle_session')
        ->assertDontSee('data-analytics-event="booking_completed"', false);
});
