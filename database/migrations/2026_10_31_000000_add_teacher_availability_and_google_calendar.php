<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_teacher')->default(false)->index();
            $table->boolean('teacher_active')->default(false)->index();
        });

        Schema::table('availability_rules', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete();
            $table->index(['user_id', 'weekday', 'is_active']);
        });

        Schema::table('availability_exceptions', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete();
            $table->index(['user_id', 'kind', 'starts_at', 'ends_at'], 'availability_exceptions_teacher_window_idx');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreignId('teacher_id')->nullable()->after('free_session_claim_id')->constrained('users')->nullOnDelete();
            $table->timestamp('teacher_assigned_at')->nullable();
            $table->foreignId('google_calendar_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('google_calendar_id')->nullable();
            $table->string('google_calendar_event_id', 128)->nullable()->unique();
            $table->timestamp('calendar_synced_at')->nullable();
            $table->text('calendar_sync_error')->nullable();
            $table->index(['teacher_id', 'starts_at', 'ends_at'], 'bookings_teacher_window_idx');
            $table->index(['teacher_id', 'teacher_assigned_at'], 'bookings_teacher_assignment_idx');
        });

        Schema::create('teacher_calendars', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('calendar_id')->default('primary');
            $table->string('calendar_email')->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('status', 24)->default('connected');
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->timestamps();
        });

        Schema::create('teacher_calendar_busy_intervals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('google_event_id', 1024);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('is_all_day')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'starts_at', 'ends_at'], 'teacher_calendar_busy_window_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_calendar_busy_intervals');
        Schema::dropIfExists('teacher_calendars');

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex('bookings_teacher_window_idx');
            $table->dropIndex('bookings_teacher_assignment_idx');
            $table->dropUnique(['google_calendar_event_id']);
            $table->dropColumn('google_calendar_id');
            $table->dropConstrainedForeignId('google_calendar_user_id');
            $table->dropConstrainedForeignId('teacher_id');
            $table->dropColumn(['teacher_assigned_at', 'google_calendar_event_id', 'calendar_synced_at', 'calendar_sync_error']);
        });

        Schema::table('availability_exceptions', function (Blueprint $table): void {
            $table->dropIndex('availability_exceptions_teacher_window_idx');
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('availability_rules', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'weekday', 'is_active']);
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['is_teacher', 'teacher_active']);
        });
    }
};
