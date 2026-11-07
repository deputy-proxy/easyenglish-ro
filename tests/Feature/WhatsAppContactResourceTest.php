<?php

use App\Filament\Resources\WhatsAppContacts\Pages\ManageWhatsAppContacts;
use App\Filament\Resources\WhatsAppContacts\WhatsAppContactResource;
use App\Models\User;
use App\Models\WhatsAppContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lists WhatsApp contacts and displays their consent status', function () {
    $staff = User::factory()->create(['is_staff' => true]);
    $consented = WhatsAppContact::recordConsent('+40722123456', 'registration');
    $optedOut = WhatsAppContact::recordConsent('+40722987654', 'booking_form');
    WhatsAppContact::recordOptOut($optedOut->phone, 'whatsapp_reply');

    Livewire::actingAs($staff)
        ->test(ManageWhatsAppContacts::class)
        ->assertCanSeeTableRecords([$consented, $optedOut])
        ->assertSee('Consented')
        ->assertSee('Opted out')
        ->assertSee('+40722123456')
        ->assertSee('+40722987654');
});

it('keeps WhatsApp contacts read-only in Filament', function () {
    $admin = User::factory()->create(['is_staff' => true, 'is_admin' => true]);
    $contact = WhatsAppContact::recordConsent('+40722123456', 'registration');

    expect(WhatsAppContactResource::canCreate())->toBeFalse()
        ->and(WhatsAppContactResource::canEdit($contact))->toBeFalse()
        ->and(WhatsAppContactResource::canDelete($contact))->toBeFalse()
        ->and(WhatsAppContactResource::canDeleteAny())->toBeFalse();

    Livewire::actingAs($admin)
        ->test(ManageWhatsAppContacts::class)
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');
});
