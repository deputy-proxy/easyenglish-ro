<?php

test('returns a successful response', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee('hero-teacher.png');
    $response->assertSee('hero-student.png');
    $response->assertSee(app()->getLocale() === 'ro' ? 'Programează prima sesiune gratuită' : 'Book your first free session');
    $response->assertSee(app()->getLocale() === 'ro' ? 'Conversație pe WhatsApp' : 'Conversation on WhatsApp');
});
