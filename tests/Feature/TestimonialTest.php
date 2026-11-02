<?php

use App\Filament\Resources\Testimonials\Pages\ManageTestimonials;
use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('does not allow a testimonial to be published without recorded consent', function () {
    expect(fn () => Testimonial::factory()->create(['is_published' => true]))
        ->toThrow(ValidationException::class);

    expect(Testimonial::query()->count())->toBe(0);
});

it('allows publishing only after publication consent has been recorded', function () {
    $testimonial = Testimonial::factory()->create([
        'publication_consent_at' => now(),
        'is_published' => true,
    ]);

    expect($testimonial->fresh()->is_published)->toBeTrue()
        ->and($testimonial->fresh()->publication_consent_at)->not->toBeNull();
});

it('renders only published consented testimonials for the active locale', function () {
    Testimonial::factory()->approvedAndPublished()->create([
        'quote' => 'I can finally speak with confidence.',
        'author_name' => 'Maria',
        'author_context' => 'Adult learner',
        'locale' => 'en',
        'sort_order' => 1,
    ]);
    Testimonial::factory()->approvedAndPublished()->create([
        'quote' => 'Unrelated Romanian quote.',
        'locale' => 'ro',
    ]);
    Testimonial::factory()->create([
        'quote' => 'Published without consent must stay hidden.',
        'locale' => 'en',
        'is_published' => false,
    ]);

    $this->withSession(['locale' => 'en'])->get('/')
        ->assertOk()
        ->assertSee('I can finally speak with confidence.')
        ->assertSee('Maria')
        ->assertSee('Adult learner')
        ->assertDontSee('Unrelated Romanian quote.')
        ->assertDontSee('Published without consent must stay hidden.')
        ->assertDontSee('Cursant anonim');
});

it('keeps the testimonials empty state when there are no eligible records', function () {
    Testimonial::factory()->create([
        'quote' => 'Not approved for publication.',
        'locale' => 'ro',
        'is_published' => false,
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('Adunăm feedback și acordul cursanților pentru publicare.')
        ->assertDontSee('Not approved for publication.');
});

it('escapes testimonial content and supports anonymous attribution', function () {
    Testimonial::factory()->approvedAndPublished()->create([
        'quote' => '<script>alert("private")</script> A useful conversation.',
        'author_name' => null,
        'author_context' => null,
        'locale' => 'ro',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('&lt;script&gt;alert(&quot;private&quot;)&lt;/script&gt;', false)
        ->assertSee('Cursant anonim')
        ->assertDontSee('<script>alert("private")</script>', false);
});

it('registers testimonial management in Filament and restricts it to staff', function () {
    $staff = User::factory()->create(['is_staff' => true]);
    $customer = User::factory()->create(['is_staff' => false]);

    $this->actingAs($staff)->get('/admin/testimonials')->assertOk();
    $this->actingAs($customer)->get('/admin/testimonials')->assertForbidden();
});

it('allows staff to create a consented testimonial from Filament', function () {
    $staff = User::factory()->create(['is_staff' => true]);

    Livewire::actingAs($staff)
        ->test(ManageTestimonials::class)
        ->callAction('create', data: [
            'quote' => 'The conversations helped me at work.',
            'author_name' => 'Alex',
            'author_context' => 'Adult learner',
            'locale' => 'en',
            'publication_consent_at' => now()->toDateTimeString(),
            'is_published' => true,
            'sort_order' => 2,
        ])
        ->assertHasNoActionErrors();

    $this->assertDatabaseHas('testimonials', [
        'quote' => 'The conversations helped me at work.',
        'author_name' => 'Alex',
        'locale' => 'en',
        'is_published' => true,
    ]);
});

it('renders published learner stories as an accessible single-story carousel', function () {
    Testimonial::factory()->approvedAndPublished()->create([
        'quote' => 'I can speak more confidently at work.',
        'author_name' => 'Maria',
        'author_context' => 'English for work',
        'locale' => 'en',
        'sort_order' => 1,
    ]);
    Testimonial::factory()->approvedAndPublished()->create([
        'quote' => 'I can follow meetings without translating every sentence.',
        'author_name' => 'Alex',
        'author_context' => 'Professional English',
        'locale' => 'en',
        'sort_order' => 2,
    ]);

    $response = $this->withSession(['locale' => 'en'])->get('/');

    $response->assertOk()
        ->assertSee('data-testimonials-carousel', false)
        ->assertSee('data-testimonial-previous', false)
        ->assertSee('data-testimonial-next', false)
        ->assertSee('data-testimonial-progress', false)
        ->assertSee('data-autoplay-duration="7000"', false)
        ->assertSee('testimonial-panel is-entering', false)
        ->assertDontSee('data-testimonial-selector', false)
        ->assertSee('I can speak more confidently at work.')
        ->assertSee('I can follow meetings without translating every sentence.')
        ->assertSee('Maria')
        ->assertSee('Alex');

    expect(substr_count($response->getContent(), 'data-testimonial-panel'))->toBe(2)
        ->and(substr_count($response->getContent(), 'data-testimonial-progress'))->toBe(2);

    $script = file_get_contents(resource_path('js/app.js'));

    expect($script)->toContain('setTimeout(() => showTestimonial(activeIndex + 1), duration)')
        ->toContain('pointerdown')
        ->toContain('pointerup')
        ->toContain('data-testimonial-previous');
});
