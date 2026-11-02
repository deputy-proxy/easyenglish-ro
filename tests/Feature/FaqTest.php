<?php

use App\Filament\Resources\Faqs\Pages\ManageFaqs;
use App\Models\Faq;
use App\Models\User;
use Livewire\Livewire;

it('returns published FAQs in display order', function () {
    Faq::factory()->published()->create(['question' => 'Later question', 'sort_order' => 20]);
    Faq::factory()->published()->create(['question' => 'First question', 'sort_order' => 10]);
    Faq::factory()->create(['question' => 'Draft question', 'sort_order' => 1]);

    expect(Faq::query()->publiclyVisible()->orderBy('sort_order')->pluck('question')->all())
        ->toBe(['First question', 'Later question']);
});

it('renders only published FAQs for the active locale in display order', function () {
    Faq::factory()->published()->create(['question' => 'Second question', 'answer' => 'Second answer', 'locale' => 'en', 'sort_order' => 20]);
    Faq::factory()->published()->create(['question' => 'First question', 'answer' => 'First answer', 'locale' => 'en', 'sort_order' => 10]);
    Faq::factory()->published()->create(['question' => 'Romanian question', 'locale' => 'ro']);
    Faq::factory()->create(['question' => 'Unpublished question', 'locale' => 'en']);

    $response = $this->withSession(['locale' => 'en'])->get('/');

    $response->assertOk()
        ->assertSee('id="faq"', false)
        ->assertDontSee('id="faqs"', false)
        ->assertSee('First question')
        ->assertSee('Second question')
        ->assertDontSee('Romanian question')
        ->assertDontSee('Unpublished question');

    expect(strpos($response->getContent(), 'First question'))
        ->toBeLessThan(strpos($response->getContent(), 'Second question'));
});

it('does not render an empty FAQ section', function () {
    Faq::factory()->create(['question' => 'Draft question']);

    $this->get('/')
        ->assertOk()
        ->assertDontSee('id="faq"', false);
});

it('escapes FAQ answers instead of rendering administrator-entered HTML', function () {
    Faq::factory()->published()->create([
        'question' => 'Safe question?',
        'answer' => '<script>alert("unsafe")</script>',
        'locale' => 'ro',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('&lt;script&gt;alert(&quot;unsafe&quot;)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert("unsafe")</script>', false);
});

it('registers FAQ management in Filament and restricts it to staff', function () {
    $staff = User::factory()->create(['is_staff' => true]);
    $customer = User::factory()->create(['is_staff' => false]);

    $this->actingAs($staff)->get('/admin/faqs')->assertOk();
    $this->actingAs($customer)->get('/admin/faqs')->assertForbidden();
});

it('allows staff to create an FAQ through Filament', function () {
    $staff = User::factory()->create(['is_staff' => true]);

    Livewire::actingAs($staff)
        ->test(ManageFaqs::class)
        ->callAction('create', data: [
            'question' => 'How does it work?',
            'answer' => 'You practise with a teacher.',
            'category' => 'Sessions',
            'locale' => 'en',
            'is_published' => true,
            'sort_order' => 10,
        ])
        ->assertHasNoActionErrors();

    $this->assertDatabaseHas('faqs', [
        'question' => 'How does it work?',
        'locale' => 'en',
        'is_published' => true,
    ]);
});
