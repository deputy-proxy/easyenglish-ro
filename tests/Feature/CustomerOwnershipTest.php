<?php

use App\Models\Booking;
use App\Models\BundleDefinition;
use App\Models\BundleEntitlement;
use App\Models\User;
use Illuminate\Support\Str;

function ownershipBooking(array $overrides = []): Booking
{
    return Booking::query()->create(array_merge([
        'public_reference' => (string) Str::uuid(),
        'customer_name' => 'Legacy Customer',
        'customer_email' => 'ownership@example.com',
        'customer_phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Ownership reconciliation',
        'accepted_policy_version' => 'test-policy',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addMinutes(15),
        'duration_minutes' => 15,
        'status' => 'confirmed',
        'locale' => 'en',
    ], $overrides));
}

test('ownership reconciliation is a dry run by default and links only verified exact email and phone matches', function () {
    $user = User::factory()->create([
        'email' => 'ownership@example.com',
        'phone' => '+40722123456',
        'phone_verified_at' => now(),
    ]);
    $booking = ownershipBooking();

    $reportPath = storage_path('framework/testing/customer-ownership-report.json');
    @unlink($reportPath);

    $this->artisan('customers:reconcile-record-ownership', ['--report' => $reportPath])
        ->expectsOutputToContain('Dry run only')
        ->assertSuccessful();

    $report = json_decode(file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
    expect($report['aggregates']['bookings']['linked_ids'])->toContain($booking->id)
        ->and($booking->fresh()->customer_user_id)->toBeNull();

    @unlink($reportPath);

    $this->artisan('customers:reconcile-record-ownership', ['--apply' => true])
        ->expectsOutputToContain('applied')
        ->assertSuccessful();

    expect($booking->fresh()->customer_user_id)->toBe($user->id);
});

test('ownership reconciliation does not link records when phone identity is missing or mismatched', function () {
    User::factory()->create([
        'email' => 'ownership@example.com',
        'phone' => '+40722999999',
        'phone_verified_at' => now(),
    ]);
    $booking = ownershipBooking();

    $this->artisan('customers:reconcile-record-ownership', ['--apply' => true])->assertSuccessful();

    expect($booking->fresh()->customer_user_id)->toBeNull();
});

test('ownership reconciliation leaves ambiguous case-insensitive account matches unlinked', function () {
    User::factory()->create([
        'email' => 'duplicate@example.com',
        'phone' => '+40722123456',
        'phone_verified_at' => now(),
    ]);
    User::factory()->create([
        'email' => 'DUPLICATE@example.com',
        'phone' => '+40722123456',
        'phone_verified_at' => now(),
    ]);
    $booking = ownershipBooking(['customer_email' => 'duplicate@example.com']);

    $this->artisan('customers:reconcile-record-ownership', ['--apply' => true])->assertSuccessful();

    expect($booking->fresh()->customer_user_id)->toBeNull();
});

test('deleting a customer account preserves transactional snapshots and unlinks ownership', function () {
    $user = User::factory()->create();
    $booking = ownershipBooking([
        'customer_user_id' => $user->id,
        'customer_email' => $user->email,
        'customer_name' => $user->name,
    ]);
    $definition = BundleDefinition::query()->firstOrCreate(
        ['name' => 'Deletion ownership bundle'],
        ['price_ron' => 156, 'paid_credits' => 4, 'included_free_credits' => 1, 'is_active' => true],
    );
    $entitlement = BundleEntitlement::query()->create([
        'customer_user_id' => $user->id,
        'bundle_definition_id' => $definition->id,
        'customer_name' => $user->name,
        'customer_email' => $user->email,
        'customer_phone' => '+40722123456',
        'status' => 'active',
        'paid_amount_ron' => 156,
        'currency' => 'RON',
        'paid_credits' => 4,
        'free_credits_granted' => 0,
        'credits_remaining' => 4,
        'free_credits_remaining' => 0,
        'activated_at' => now(),
        'expires_at' => now()->addMonths(3),
    ]);

    $user->delete();

    expect($booking->fresh()->customer_user_id)->toBeNull()
        ->and($booking->fresh()->customer_email)->toBe($user->email)
        ->and($booking->fresh()->customer_name)->toBe($user->name)
        ->and($entitlement->fresh()->customer_user_id)->toBeNull()
        ->and($entitlement->fresh()->customer_email)->toBe($user->email);
});

test('bundle entitlement reconciliation requires recorded contact verification snapshots', function () {
    $user = User::factory()->create([
        'email' => 'entitlement-owner@example.com',
        'phone' => '+40722123456',
        'phone_verified_at' => now(),
    ]);
    $definition = BundleDefinition::query()->firstOrCreate(
        ['name' => 'Customer ownership bundle'],
        ['price_ron' => 156, 'paid_credits' => 4, 'included_free_credits' => 1, 'is_active' => true],
    );
    $entitlement = BundleEntitlement::query()->create([
        'bundle_definition_id' => $definition->id,
        'customer_name' => $user->name,
        'customer_email' => $user->email,
        'customer_phone' => $user->phone,
        'status' => 'active',
        'paid_amount_ron' => 156,
        'currency' => 'RON',
        'paid_credits' => 4,
        'free_credits_granted' => 0,
        'credits_remaining' => 4,
        'free_credits_remaining' => 0,
        'customer_email_verified_at' => now(),
        'customer_phone_verified_at' => null,
    ]);

    $this->artisan('customers:reconcile-record-ownership', ['--apply' => true])->assertSuccessful();
    expect($entitlement->fresh()->customer_user_id)->toBeNull();

    $entitlement->forceFill(['customer_phone_verified_at' => now()])->save();
    $this->artisan('customers:reconcile-record-ownership', ['--apply' => true])->assertSuccessful();

    expect($entitlement->fresh()->customer_user_id)->toBe($user->id);
});

test('legacy booking compatibility requires both verified email and exact phone identity', function () {
    $user = User::factory()->create([
        'email' => 'ownership@example.com',
        'phone' => '+40722123456',
        'phone_verified_at' => now(),
    ]);
    $booking = ownershipBooking();

    expect(Booking::query()->ownedBy($user)->whereKey($booking->id)->exists())->toBeTrue();

    $user->forceFill(['phone_verified_at' => null])->save();

    expect(Booking::query()->ownedBy($user)->whereKey($booking->id)->exists())->toBeFalse();
});

test('legacy ownership reconciliation never reclaims records revoked by account deletion', function () {
    $original = User::factory()->create([
        'email' => 'revoked-owner@example.com',
        'phone' => '+40722123456',
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);
    $booking = ownershipBooking([
        'customer_user_id' => $original->id,
        'customer_email' => $original->email,
        'customer_phone' => $original->phone,
    ]);

    $original->delete();
    $newAccount = User::factory()->create([
        'email' => 'revoked-owner@example.com',
        'phone' => '+40722123456',
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);

    $this->artisan('customers:reconcile-record-ownership', ['--apply' => true])->assertSuccessful();

    expect($booking->fresh()->customer_user_id)->toBeNull()
        ->and($booking->fresh()->customer_ownership_revoked_at)->not->toBeNull()
        ->and(Booking::query()->ownedBy($newAccount)->whereKey($booking->id)->exists())->toBeFalse();
});
