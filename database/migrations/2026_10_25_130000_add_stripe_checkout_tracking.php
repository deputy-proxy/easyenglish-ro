<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bundle_entitlements', function (Blueprint $table): void {
            $table->string('checkout_request_key', 80)->nullable()->unique()->after('status');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->string('checkout_request_key', 120)->nullable()->unique()->after('provider_checkout_id');
            $table->text('checkout_url')->nullable()->after('checkout_request_key');
            $table->timestamp('checkout_expires_at')->nullable()->after('checkout_url');
            $table->timestamp('last_reconciled_at')->nullable()->after('confirmed_at');
            $table->unsignedInteger('refunded_amount_ron')->default(0)->after('last_reconciled_at');
            $table->text('last_error')->nullable()->after('refunded_amount_ron');
        });

        Schema::create('stripe_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_event_id', 255)->unique();
            $table->string('event_type', 120);
            $table->char('payload_sha256', 64);
            $table->string('status', 24)->default('received');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('payment_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->string('provider_refund_id', 255)->nullable()->unique();
            $table->unsignedInteger('amount_ron');
            $table->char('currency', 3)->default('RON');
            $table->unsignedSmallInteger('credits_to_revoke')->default(0);
            $table->unsignedSmallInteger('free_credits_to_revoke')->default(0);
            $table->string('status', 24)->default('pending');
            $table->string('provider_status', 24)->nullable();
            $table->string('reason', 500);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 120)->unique();
            $table->json('metadata')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['payment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
        Schema::dropIfExists('stripe_webhook_events');
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['checkout_request_key']);
            $table->dropColumn(['checkout_request_key', 'checkout_url', 'checkout_expires_at', 'last_reconciled_at', 'refunded_amount_ron', 'last_error']);
        });
        Schema::table('bundle_entitlements', function (Blueprint $table): void {
            $table->dropUnique(['checkout_request_key']);
            $table->dropColumn('checkout_request_key');
        });
    }
};
