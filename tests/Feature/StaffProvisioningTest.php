<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

function staffProvisioningPassword(): string
{
    return 'SecureStaffPassword123!';
}

test('staff provisioning command creates a verified staff account without displaying its password', function () {
    $password = staffProvisioningPassword();

    $this->artisan('staff:provision staff@example.com --name="Staff Operator"')
        ->expectsQuestion('Set an initial password (minimum 12 characters)', $password)
        ->expectsQuestion('Confirm initial password', $password)
        ->expectsOutputToContain('Staff account provisioned for staff@example.com')
        ->doesntExpectOutputToContain($password)
        ->assertSuccessful();

    $user = User::query()->where('email', 'staff@example.com')->firstOrFail();
    expect($user->is_staff)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check($password, $user->password))->toBeTrue();
});

test('staff provisioning command refuses mismatched passwords and creates no account', function () {
    $this->artisan('staff:provision staff@example.com --name="Staff Operator"')
        ->expectsQuestion('Set an initial password (minimum 12 characters)', 'SecureStaffPassword123!')
        ->expectsQuestion('Confirm initial password', 'DifferentPassword123!')
        ->expectsOutputToContain('confirmation')
        ->assertFailed();

    $this->assertDatabaseMissing('users', ['email' => 'staff@example.com']);
});

test('staff provisioning upgrades an existing account and is idempotent for existing staff', function () {
    $user = User::factory()->create(['email' => 'existing@example.com']);

    $this->artisan('staff:provision existing@example.com --name="Ignored Name"')
        ->expectsQuestion('Set an initial password (minimum 12 characters)', 'SecureStaffPassword123!')
        ->expectsQuestion('Confirm initial password', 'SecureStaffPassword123!')
        ->assertSuccessful();

    expect($user->fresh()->is_staff)->toBeTrue()
        ->and($user->fresh()->email_verified_at)->not->toBeNull();

    $this->artisan('staff:provision existing@example.com')
        ->expectsOutputToContain('already provisioned as staff')
        ->assertSuccessful();
});
