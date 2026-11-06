<?php

use App\Livewire\Settings\Appearance;
use App\Models\User;
use Livewire\Livewire;

test('appearance settings use the branded dashboard shell', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('appearance.edit'))
        ->assertOk()
        ->assertSee('dashboard-topbar')
        ->assertSee('dashboard-panel')
        ->assertSee('EasyEnglish')
        ->assertSee(route('dashboard', ['view' => 'account']), false)
        ->assertSee(__('public.dashboard_primary_navigation'))
        ->assertSee(__('public.dashboard_account'))
        ->assertSee('aria-current="page"', false)
        ->assertDontSee('aria-label="Settings"', false)
        ->assertDontSee('Repository')
        ->assertDontSee('Documentation');
});

test('appearance setting page retains its appearance controls', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Appearance::class)
        ->assertSee('Light')
        ->assertSee('Dark')
        ->assertSee('System');
});
