<?php

test('returns a successful response', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee('hero-backdrop');
    $response->assertSee(app()->getLocale() === 'ro' ? 'Programează prima ședință complet gratuit' : 'Book your first session completely free');
    $response->assertSee(app()->getLocale() === 'ro' ? 'Conversație pe WhatsApp' : 'Conversation on WhatsApp');
});
