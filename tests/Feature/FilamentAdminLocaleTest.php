<?php

use Illuminate\Support\Facades\App;

it('keeps the Filament admin in English regardless of the active application locale', function () {
    App::setLocale('ro');

    $this->get('/admin/login')->assertOk();

    expect(App::currentLocale())->toBe('en');
});
