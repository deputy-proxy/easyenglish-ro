<?php

use App\Filament\Resources\Enquiries\EnquiryResource;
use App\Filament\Resources\Enquiries\Pages\ManageEnquiries;
use App\Models\Enquiry;
use App\Models\User;
use Livewire\Livewire;

it('renders the Romanian contact page with the shared public header and footer', function (): void {
    $this->withSession(['locale' => 'en'])->get('/contact')
        ->assertOk()
        ->assertSee('<html lang="ro">', false)
        ->assertSee('Hai să vorbim.')
        ->assertSee('name="name"', false)
        ->assertSee('name="email"', false)
        ->assertSee('name="subject"', false)
        ->assertSee('name="message"', false)
        ->assertSee('href="'.route('contact').'"', false);
});

it('stores a valid public contact submission as a new enquiry', function (): void {
    $this->post('/contact', [
        'name' => '  Ana Popescu  ', 'email' => ' ANA@example.com ',
        'subject' => 'Întrebare despre sesiuni', 'message' => 'Aș dori mai multe informații despre sesiunile de conversație.', 'website' => '',
    ])->assertRedirect(route('contact'))->assertSessionHas('contact_success');

    $this->assertDatabaseHas('enquiries', [
        'name' => 'Ana Popescu', 'email' => 'ana@example.com', 'subject' => 'Întrebare despre sesiuni', 'status' => Enquiry::STATUS_NEW,
    ]);
    expect(Enquiry::query()->count())->toBe(1);
});

it('validates required fields, email format, and message length without storing invalid submissions', function (): void {
    $this->from('/contact')->post('/contact', ['name' => 'A', 'email' => 'not-an-email', 'subject' => '', 'message' => 'short'])
        ->assertRedirect('/contact')->assertSessionHasErrors(['name', 'email', 'subject', 'message']);
    expect(Enquiry::query()->count())->toBe(0);
});

it('rejects a filled honeypot without storing an enquiry', function (): void {
    $this->post('/contact', [
        'name' => 'Bot', 'email' => 'bot@example.com', 'subject' => 'Spam', 'message' => 'This is a bot submission.', 'website' => 'https://spam.example',
    ])->assertSessionHasErrors('website');
    expect(Enquiry::query()->count())->toBe(0);
});

it('limits repeated contact form submissions', function (): void {
    for ($index = 0; $index < 5; $index++) {
        $this->post('/contact', ['name' => 'Test Sender', 'email' => "sender{$index}@example.com", 'subject' => 'Test message', 'message' => 'This is a sufficiently long test message.'])
            ->assertRedirect(route('contact'));
    }
    $this->post('/contact', ['name' => 'Test Sender', 'email' => 'sixth@example.com', 'subject' => 'Test message', 'message' => 'This is a sufficiently long test message.'])
        ->assertTooManyRequests();
    expect(Enquiry::query()->count())->toBe(5);
});

it('exposes enquiry administration to staff only and allows status updates', function (): void {
    $enquiry = Enquiry::query()->create(['name' => 'Staff Test', 'email' => 'staff-test@example.com', 'subject' => 'Test enquiry', 'message' => 'A full enquiry message for the staff workflow.']);
    $staff = User::factory()->create(['is_staff' => true, 'is_admin' => false]);
    $customer = User::factory()->create(['is_staff' => false]);

    $this->actingAs($staff)->get('/admin/enquiries')->assertOk();
    $this->actingAs($customer)->get('/admin/enquiries')->assertForbidden();

    $this->actingAs($staff)->get(EnquiryResource::getUrl('view', ['record' => $enquiry]))
        ->assertOk()->assertSee('A full enquiry message for the staff workflow.');

    Livewire::actingAs($staff)->test(ManageEnquiries::class)
        ->callTableAction('updateStatus', $enquiry, data: ['status' => Enquiry::STATUS_IN_PROGRESS])
        ->assertHasNoTableActionErrors();
    expect($enquiry->fresh()->status)->toBe(Enquiry::STATUS_IN_PROGRESS);
});

it('does not permit creating or deleting enquiries through Filament', function (): void {
    $staff = User::factory()->create(['is_staff' => true]);
    $enquiry = Enquiry::query()->create(['name' => 'Read Only', 'email' => 'readonly@example.com', 'subject' => 'Read-only record', 'message' => 'This record must remain available for audit purposes.']);
    expect(Enquiry::query()->count())->toBe(1);
    $this->actingAs($staff)->get('/admin/enquiries')->assertOk();
    expect($enquiry->fresh())->not->toBeNull();
});
