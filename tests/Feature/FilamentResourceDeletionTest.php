<?php

use App\Filament\Resources\AvailabilityExceptions\Pages\ManageAvailabilityExceptions;
use App\Filament\Resources\AvailabilityRules\Pages\ManageAvailabilityRules;
use App\Filament\Resources\BookingAudits\BookingAuditResource;
use App\Filament\Resources\BookingAudits\Pages\ManageBookingAudits;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Bookings\Pages\ManageBookings;
use App\Filament\Resources\BundleDefinitions\Pages\ManageBundleDefinitions;
use App\Filament\Resources\BundleEntitlements\BundleEntitlementResource;
use App\Filament\Resources\BundleEntitlements\Pages\ManageBundleEntitlements;
use App\Filament\Resources\CallEvents\CallEventResource;
use App\Filament\Resources\CallEvents\Pages\ManageCallEvents;
use App\Filament\Resources\CreditTransactions\CreditTransactionResource;
use App\Filament\Resources\CreditTransactions\Pages\ManageCreditTransactions;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\ManageCustomers;
use App\Filament\Resources\Faqs\FaqResource;
use App\Filament\Resources\Faqs\Pages\ManageFaqs;
use App\Filament\Resources\Messages\MessageResource;
use App\Filament\Resources\Messages\Pages\ManageMessages;
use App\Filament\Resources\NotificationDeliveries\NotificationDeliveryResource;
use App\Filament\Resources\NotificationDeliveries\Pages\ManageNotificationDeliveries;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\ProgressReports\Pages\ManageProgressReports;
use App\Filament\Resources\Refunds\Pages\ManageRefunds;
use App\Filament\Resources\Refunds\RefundResource;
use App\Filament\Resources\SessionFeedback\Pages\ManageSessionFeedback;
use App\Filament\Resources\Teachers\Pages\ManageTeachers;
use App\Filament\Resources\Teachers\TeacherResource;
use App\Filament\Resources\Testimonials\Pages\ManageTestimonials;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Booking;
use App\Models\BookingAudit;
use App\Models\BookingCallEvent;
use App\Models\BundleEntitlement;
use App\Models\CreditTransaction;
use App\Models\Faq;
use App\Models\Message;
use App\Models\NotificationDelivery;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('registers a per-record delete action on every resource that permits deletion', function () {
    $staff = User::factory()->create(['is_staff' => true, 'is_admin' => true]);

    foreach ([
        ManageAvailabilityExceptions::class,
        ManageAvailabilityRules::class,
        ManageBundleDefinitions::class,
        ManageFaqs::class,
        ManageProgressReports::class,
        ManageSessionFeedback::class,
        ManageTestimonials::class,
    ] as $page) {
        Livewire::actingAs($staff)
            ->test($page)
            ->assertTableActionExists('delete');
    }
});

it('keeps delete actions unavailable on resources that protect operational and account records', function () {
    $staff = User::factory()->create(['is_staff' => true, 'is_admin' => true]);

    foreach ([
        ManageBookingAudits::class,
        ManageBookings::class,
        ManageBundleEntitlements::class,
        ManageCallEvents::class,
        ManageCreditTransactions::class,
        ManageCustomers::class,
        ManageMessages::class,
        ManageNotificationDeliveries::class,
        ManagePayments::class,
        ManageRefunds::class,
        ManageTeachers::class,
        ManageUsers::class,
    ] as $page) {
        Livewire::actingAs($staff)
            ->test($page)
            ->assertTableActionDoesNotExist('delete');
    }
});

it('explicitly denies deletion for protected resources', function () {
    expect(BookingAuditResource::canDelete(new BookingAudit))->toBeFalse()
        ->and(BookingResource::canDelete(new Booking))->toBeFalse()
        ->and(BundleEntitlementResource::canDelete(new BundleEntitlement))->toBeFalse()
        ->and(CallEventResource::canDelete(new BookingCallEvent))->toBeFalse()
        ->and(CreditTransactionResource::canDelete(new CreditTransaction))->toBeFalse()
        ->and(CustomerResource::canDelete(new User))->toBeFalse()
        ->and(MessageResource::canDelete(new Message))->toBeFalse()
        ->and(NotificationDeliveryResource::canDelete(new NotificationDelivery))->toBeFalse()
        ->and(PaymentResource::canDelete(new Payment))->toBeFalse()
        ->and(RefundResource::canDelete(new PaymentRefund))->toBeFalse()
        ->and(TeacherResource::canDelete(new User))->toBeFalse()
        ->and(UserResource::canDelete(new User))->toBeFalse();
});

it('deletes an eligible record through the confirmed Filament row action', function () {
    $staff = User::factory()->create(['is_staff' => true, 'is_admin' => true]);
    $faq = Faq::query()->create([
        'question' => 'Can I delete this FAQ?',
        'answer' => 'Yes, when it is no longer needed.',
        'locale' => 'en',
    ]);

    Livewire::actingAs($staff)
        ->test(ManageFaqs::class)
        ->callTableAction('delete', $faq);

    $this->assertDatabaseMissing('faqs', ['id' => $faq->id]);
});

it('does not expose the delete-capable admin page to non-staff accounts', function () {
    $customer = User::factory()->create(['is_staff' => false]);

    $this->actingAs($customer)
        ->get('/admin/faqs')
        ->assertForbidden();
});

it('allows staff to use Filament but hides and rejects deletion actions', function () {
    $staff = User::factory()->create(['is_staff' => true, 'is_admin' => false]);
    $faq = Faq::query()->create([
        'question' => 'Staff cannot delete this FAQ',
        'answer' => 'Deletion is an administrator capability.',
        'locale' => 'en',
    ]);

    $this->actingAs($staff)->get('/admin/faqs')->assertOk();

    Livewire::actingAs($staff)
        ->test(ManageFaqs::class)
        ->assertTableActionHidden('delete', $faq)
        ->assertTableBulkActionHidden('delete');

    $this->actingAs($staff);

    expect(FaqResource::canDelete($faq))->toBeFalse()
        ->and(FaqResource::canDeleteAny())->toBeFalse();

    $this->assertDatabaseHas('faqs', ['id' => $faq->id]);
});

it('allows only administrators to use per-record and bulk deletion', function () {
    $admin = User::factory()->create(['is_staff' => true, 'is_admin' => true]);
    $faq = Faq::query()->create([
        'question' => 'Admin can delete this FAQ',
        'answer' => 'Administrator deletion is permitted.',
        'locale' => 'en',
    ]);

    $this->actingAs($admin);

    expect(FaqResource::canDelete($faq))->toBeTrue()
        ->and(FaqResource::canDeleteAny())->toBeTrue();

    Livewire::actingAs($admin)
        ->test(ManageFaqs::class)
        ->assertTableActionExists('delete')
        ->assertTableBulkActionExists('delete')
        ->callTableAction('delete', $faq);

    $this->assertDatabaseMissing('faqs', ['id' => $faq->id]);
});
