<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_call_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 32);
            $table->unsignedSmallInteger('revision');
            $table->string('idempotency_key', 120)->unique();
            $table->string('provider_event_id', 255)->nullable();
            $table->text('join_url')->nullable();
            $table->string('call_type', 16)->default('video');
            $table->dateTime('scheduled_start_at');
            $table->dateTime('scheduled_end_at');
            $table->string('status', 24)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->string('last_error_category', 64)->nullable();
            $table->string('last_error_message', 255)->nullable();
            $table->timestamps();

            $table->unique(['booking_id', 'provider', 'revision'], 'booking_call_events_booking_provider_revision_unique');
            $table->unique(['provider', 'provider_event_id'], 'booking_call_events_provider_event_unique');
            $table->index(['status', 'next_attempt_at'], 'booking_call_events_status_retry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_call_events');
    }
};
