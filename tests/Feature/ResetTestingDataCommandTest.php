<?php

use App\Models\BundleDefinition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function resetTestingDataFixture(array $attributes = []): User
{
    return User::factory()->create($attributes);
}

test('testing data reset previews rows without deleting them', function () {
    $customer = resetTestingDataFixture(['email' => 'reset-customer@example.com']);
    DB::table('whatsapp_contacts')->insert([
        'phone' => '+40722123456',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('app:reset-testing-data')
        ->expectsOutputToContain('Preview only')
        ->assertSuccessful();

    expect(User::query()->whereKey($customer->id)->exists())->toBeTrue()
        ->and(DB::table('whatsapp_contacts')->where('phone', '+40722123456')->exists())->toBeTrue();
});

test('testing data reset removes customers and transactional data but preserves content and internal accounts', function () {
    $customer = resetTestingDataFixture(['email' => 'reset-customer@example.com']);
    $teacher = resetTestingDataFixture(['is_teacher' => true, 'teacher_active' => true]);
    $teacher->teacherProfile()->create([]);
    $staff = resetTestingDataFixture(['is_staff' => true]);
    $admin = resetTestingDataFixture(['is_admin' => true]);

    $bundle = BundleDefinition::query()->create([
        'name' => 'Preserved test bundle',
        'price_ron' => 156,
        'paid_credits' => 4,
        'included_free_credits' => 1,
        'is_active' => true,
    ]);

    DB::table('testimonials')->insert([
        'quote' => 'A preserved testimonial',
        'locale' => 'ro',
        'is_published' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('faqs')->insert([
        'question' => 'A preserved question',
        'answer' => 'A preserved answer',
        'locale' => 'ro',
        'is_published' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('whatsapp_contacts')->insert([
        'phone' => '+40722123456',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('messages')->insert([
        'provider_message_id' => 'reset-test-message',
        'channel' => 'whatsapp',
        'direction' => 'inbound',
        'from_phone' => '+40722123456',
        'type' => 'text',
        'body' => 'Test message',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('contact_verification_challenges')->insert([
        'id' => (string) Str::uuid(),
        'customer_email' => $customer->email,
        'customer_phone' => '+40722123456',
        'locale' => 'ro',
        'return_to' => 'booking',
        'email_token_hash' => str_repeat('a', 64),
        'phone_code_hash' => str_repeat('b', 64),
        'expires_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('enquiries')->insert([
        'name' => 'Test enquiry',
        'email' => $customer->email,
        'subject' => 'Testing',
        'message' => 'Test enquiry message',
        'status' => 'new',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $entitlementId = DB::table('bundle_entitlements')->insertGetId([
        'bundle_definition_id' => $bundle->id,
        'customer_user_id' => $customer->id,
        'customer_name' => $customer->name,
        'customer_email' => $customer->email,
        'customer_phone' => '+40722123456',
        'status' => 'active',
        'paid_amount_ron' => 156,
        'currency' => 'RON',
        'paid_credits' => 4,
        'free_credits_granted' => 0,
        'credits_remaining' => 4,
        'free_credits_remaining' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $bookingId = DB::table('bookings')->insertGetId([
        'public_reference' => (string) Str::uuid(),
        'bundle_entitlement_id' => $entitlementId,
        'customer_user_id' => $customer->id,
        'customer_name' => $customer->name,
        'customer_email' => $customer->email,
        'customer_phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Practise speaking',
        'accepted_policy_version' => 'test-policy',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addMinutes(15),
        'duration_minutes' => 15,
        'status' => 'confirmed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('booking_call_events')->insert([
        'booking_id' => $bookingId,
        'provider' => 'openwa',
        'revision' => 1,
        'idempotency_key' => 'reset-test-call-event',
        'call_type' => 'video',
        'scheduled_start_at' => now()->addDay(),
        'scheduled_end_at' => now()->addDay()->addMinutes(15),
        'status' => 'scheduled',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('app:reset-testing-data', ['--force' => true])
        ->assertSuccessful();

    expect(User::query()->whereKey($customer->id)->exists())->toBeFalse()
        ->and(User::query()->whereKey($teacher->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($staff->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($admin->id)->exists())->toBeTrue()
        ->and(DB::table('student_profiles')->where('user_id', $customer->id)->exists())->toBeFalse()
        ->and(DB::table('bundle_entitlements')->exists())->toBeFalse()
        ->and(DB::table('bookings')->exists())->toBeFalse()
        ->and(DB::table('booking_call_events')->exists())->toBeFalse()
        ->and(DB::table('whatsapp_contacts')->exists())->toBeFalse()
        ->and(DB::table('messages')->exists())->toBeFalse()
        ->and(DB::table('contact_verification_challenges')->exists())->toBeFalse()
        ->and(DB::table('enquiries')->exists())->toBeFalse()
        ->and(BundleDefinition::query()->whereKey($bundle->id)->exists())->toBeTrue()
        ->and(DB::table('teacher_profiles')->where('user_id', $teacher->id)->exists())->toBeTrue()
        ->and(DB::table('testimonials')->where('quote', 'A preserved testimonial')->exists())->toBeTrue()
        ->and(DB::table('faqs')->where('question', 'A preserved question')->exists())->toBeTrue();
});

test('testing data reset refuses to run in production', function () {
    app()->instance('env', 'production');

    $this->artisan('app:reset-testing-data', ['--force' => true])
        ->assertFailed();
});
