<?php

use App\Models\User;

it('adds baseline security headers to public responses', function (): void {
    $this->get('/contact')
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeaderMissing('Strict-Transport-Security');
});

it('adds baseline security headers to authenticated responses', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

it('adds HSTS only to secure requests', function (): void {
    $this->get('https://easyenglish.test/contact')
        ->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

it('sets secure HttpOnly SameSite cookies when secure sessions are enabled', function (): void {
    config([
        'session.secure' => true,
        'session.http_only' => true,
        'session.same_site' => 'lax',
    ]);

    $response = $this->get(route('login'));
    $sessionCookie = collect($response->headers->getCookies())
        ->first(fn ($cookie): bool => $cookie->getName() === config('session.cookie'));

    expect($sessionCookie)->not->toBeNull();
    expect($sessionCookie->isSecure())->toBeTrue();
    expect($sessionCookie->isHttpOnly())->toBeTrue();
    expect($sessionCookie->getSameSite())->toBe('lax');
});
