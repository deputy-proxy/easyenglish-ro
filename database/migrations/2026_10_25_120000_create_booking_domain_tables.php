<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_mutexes', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->timestamps();
        });
        DB::table('booking_mutexes')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_staff')->default(false)->index();
        });

        Schema::create('availability_rules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('weekday');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['weekday', 'is_active']);
        });

        Schema::create('availability_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 24); // availability_override or blocked
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['kind', 'starts_at', 'ends_at']);
        });

        Schema::create('bundle_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->unsignedInteger('price_ron');
            $table->unsignedSmallInteger('paid_credits');
            $table->unsignedSmallInteger('included_free_credits')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique('name');
        });

        DB::table('bundle_definitions')->insert([
            ['name' => 'Starter: 1 free + 4 paid', 'price_ron' => 156, 'paid_credits' => 4, 'included_free_credits' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Standard: 1 free + 10 paid', 'price_ron' => 390, 'paid_credits' => 10, 'included_free_credits' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Extended: 1 free + 20 paid', 'price_ron' => 780, 'paid_credits' => 20, 'included_free_credits' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::create('bundle_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bundle_definition_id')->constrained()->restrictOnDelete();
            $table->string('customer_name', 160);
            $table->string('customer_email', 254)->index();
            $table->string('customer_phone', 32)->index();
            $table->timestamp('customer_email_verified_at')->nullable();
            $table->timestamp('customer_phone_verified_at')->nullable();
            $table->string('status', 24)->default('pending_payment');
            $table->unsignedInteger('paid_amount_ron');
            $table->char('currency', 3)->default('RON');
            $table->unsignedSmallInteger('paid_credits');
            $table->unsignedSmallInteger('free_credits_granted')->default(0);
            $table->unsignedSmallInteger('credits_remaining')->default(0);
            $table->unsignedSmallInteger('free_credits_remaining')->default(0);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
        });

        Schema::create('free_session_claims', function (Blueprint $table): void {
            $table->id();
            $table->string('customer_email', 254)->index();
            $table->string('customer_phone', 32)->index();
            $table->string('status', 24)->default('reserved');
            $table->foreignId('bundle_entitlement_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->unique();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->unique(['customer_email', 'customer_phone']);
        });

        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->string('public_reference', 36)->unique();
            $table->foreignId('bundle_entitlement_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('free_session_claim_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name', 160);
            $table->string('customer_email', 254)->index();
            $table->string('customer_phone', 32)->index();
            $table->string('english_level', 16);
            $table->string('session_goal', 500);
            $table->text('notes')->nullable();
            $table->string('accepted_policy_version', 64);
            $table->dateTime('starts_at'); // stored as UTC by application
            $table->dateTime('ends_at');
            $table->unsignedTinyInteger('duration_minutes')->default(15);
            $table->string('status', 24)->default('pending');
            $table->string('credit_type', 16)->nullable();
            $table->timestamp('hold_expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'starts_at']);
            $table->index(['status', 'hold_expires_at']);
            $table->index(['starts_at', 'ends_at']);
        });

        Schema::table('free_session_claims', function (Blueprint $table): void {
            $table->foreign('booking_id')->references('id')->on('bookings')->nullOnDelete();
        });

        Schema::create('credit_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bundle_entitlement_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 24);
            $table->string('credit_type', 16)->default('paid');
            $table->integer('quantity');
            $table->unsignedInteger('balance_after');
            $table->string('reason', 500)->nullable();
            $table->string('idempotency_key', 100)->unique();
            $table->timestamps();
            $table->index(['bundle_entitlement_id', 'created_at']);
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bundle_entitlement_id')->constrained()->restrictOnDelete();
            $table->string('provider', 32)->default('stripe');
            $table->string('provider_checkout_id')->nullable()->unique();
            $table->string('provider_payment_id')->nullable()->unique();
            $table->unsignedInteger('amount_ron');
            $table->char('currency', 3)->default('RON');
            $table->string('status', 24)->default('pending');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bundle_entitlement_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 16);
            $table->string('event', 64);
            $table->string('status', 24)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('idempotency_key', 120)->unique();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });

        Schema::create('booking_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bundle_entitlement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('action', 64);
            $table->string('reason', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['booking_id', 'created_at']);
            $table->index(['subject_type', 'subject_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_audits');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('credit_transactions');
        Schema::table('free_session_claims', function (Blueprint $table): void {
            $table->dropForeign(['booking_id']);
        });
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('free_session_claims');
        Schema::dropIfExists('bundle_entitlements');
        Schema::dropIfExists('bundle_definitions');
        Schema::dropIfExists('availability_exceptions');
        Schema::dropIfExists('availability_rules');
        Schema::dropIfExists('booking_mutexes');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_staff');
        });
    }
};
