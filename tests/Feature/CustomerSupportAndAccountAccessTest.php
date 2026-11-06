<?php

use App\Models\User;
use App\Models\WhatsAppContact;

test('account view exposes existing account settings and masks the customer phone', function () {
    $user = User::factory()->create([
        'phone' => '+40722123456',
        'phone_verified_at' => now(),
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'account']))
        ->assertOk()
        ->assertSee(__('public.dashboard_account_title'))
        ->assertSee('•••• 3456')
        ->assertSee(route('profile.edit'), false)
        ->assertSee(route('security.edit'), false)
        ->assertSee(route('appearance.edit'), false)
        ->assertDontSee('+40722123456');
});

test('account view gates security and appearance until email is verified', function () {
    $user = User::factory()->create(['phone' => '+40722123456', 'email_verified_at' => null]);

    $this->actingAs($user)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'account']))
        ->assertOk()
        ->assertSee(__('public.dashboard_account_security_requires_email'))
        ->assertDontSee(route('security.edit'), false)
        ->assertDontSee(route('appearance.edit'), false);
});

test('support links use configured official channels and do not change whatsapp consent', function () {
    config([
        'easyenglish.contact_email' => 'help@easyenglish.ro',
        'easyenglish.contact_whatsapp' => '+40700000000',
    ]);
    $user = User::factory()->create(['phone' => '+40722123456']);
    $contact = WhatsAppContact::recordConsent($user->phone, 'test');
    $contact->update(['opted_out_at' => now(), 'opt_out_source' => 'STOP']);

    $this->actingAs($user)->withSession(['locale' => 'en'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('https://wa.me/40700000000?text=', false)
        ->assertSee('mailto:help@easyenglish.ro', false)
        ->assertSee(__('public.dashboard_support_whatsapp_note'));

    expect($contact->fresh()->opted_out_at)->not->toBeNull()
        ->and($contact->fresh()->consented_at)->not->toBeNull();
});

test('support destinations are hidden when not configured or invalid', function () {
    config([
        'easyenglish.contact_email' => 'not-an-email',
        'easyenglish.contact_whatsapp' => 'teacher personal number',
    ]);

    $this->actingAs(User::factory()->create())->withSession(['locale' => 'en'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('wa.me')
        ->assertDontSee('mailto:')
        ->assertDontSee(__('public.dashboard_support_title'));
});

test('phone verification reuses the existing configured whatsapp flow and email-only mode is respected', function () {
    config([
        'services.contact_verification.mode' => 'email_only',
        'services.whatsapp.enabled' => true,
        'services.whatsapp.access_token' => 'configured-token',
        'services.whatsapp.phone_number_id' => '123',
        'services.whatsapp.templates.contact_verification' => 'template',
    ]);
    $user = User::factory()->create(['phone' => '+40722123456', 'phone_verified_at' => null]);

    $this->actingAs($user)->withSession(['locale' => 'en'])
        ->get(route('dashboard', ['view' => 'account']))
        ->assertOk()
        ->assertSee(__('public.dashboard_account_phone_email_only'))
        ->assertDontSee(__('public.dashboard_account_phone_verify_link'));
});
