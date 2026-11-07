<?php

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Teachers\Pages\ManageTeachers;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('users resource includes staff, teachers, and customers', function () {
    $staff = User::factory()->create(['is_staff' => true]);
    $teacher = User::factory()->create(['is_teacher' => true]);
    $customer = User::factory()->create();

    expect(UserResource::getEloquentQuery()->pluck('id')->all())
        ->toContain($staff->id, $teacher->id, $customer->id);
});

test('customers resource includes only non-staff non-teacher users', function () {
    $customer = User::factory()->create();
    $teacher = User::factory()->create(['is_teacher' => true]);
    $staff = User::factory()->create(['is_staff' => true]);
    $staffTeacher = User::factory()->create(['is_staff' => true, 'is_teacher' => true]);

    expect(CustomerResource::getEloquentQuery()->pluck('id')->all())
        ->toContain($customer->id)
        ->not->toContain($teacher->id, $staff->id, $staffTeacher->id);
});

test('creating a teacher through the teachers resource creates a user account flagged as a teacher', function () {
    $admin = User::factory()->create(['is_staff' => true]);

    Livewire::actingAs($admin)
        ->test(ManageTeachers::class)
        ->callAction('create', data: [
            'name' => 'New Teacher',
            'email' => 'new.teacher@example.com',
            'password' => 'A-secure-password-123',
            'teacher_active' => true,
        ]);

    $teacher = User::query()->where('email', 'new.teacher@example.com')->first();

    expect($teacher)->not->toBeNull()
        ->and($teacher->is_teacher)->toBeTrue()
        ->and($teacher->teacher_active)->toBeTrue();
});

test('administrator access is separate from staff access and always grants panel access', function () {
    $staff = User::factory()->create(['is_staff' => true, 'is_admin' => false]);
    $admin = User::factory()->create(['is_staff' => false, 'is_admin' => true]);

    expect($staff->isAdmin())->toBeFalse()
        ->and($admin->fresh()->isAdmin())->toBeTrue()
        ->and($admin->fresh()->is_staff)->toBeTrue();
});

test('staff cannot grant administrator access through user management', function () {
    $staff = User::factory()->create(['is_staff' => true, 'is_admin' => false]);

    Livewire::actingAs($staff)
        ->test(ManageUsers::class)
        ->callAction('create', data: [
            'name' => 'Attempted Admin',
            'email' => 'attempted.admin@example.com',
            'password' => 'A-secure-password-123',
            'is_staff' => true,
            'is_admin' => true,
        ]);

    $created = User::query()->where('email', 'attempted.admin@example.com')->firstOrFail();

    expect($created->isAdmin())->toBeFalse();
});

test('administrators can grant administrator access through user management', function () {
    $admin = User::factory()->create(['is_staff' => true, 'is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(ManageUsers::class)
        ->callAction('create', data: [
            'name' => 'New Admin',
            'email' => 'new.admin@example.com',
            'password' => 'A-secure-password-123',
            'is_admin' => true,
        ]);

    $created = User::query()->where('email', 'new.admin@example.com')->firstOrFail();

    expect($created->isAdmin())->toBeTrue()
        ->and($created->is_staff)->toBeTrue();
});
