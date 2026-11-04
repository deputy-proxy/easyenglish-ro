<?php

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Teachers\Pages\ManageTeachers;
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
