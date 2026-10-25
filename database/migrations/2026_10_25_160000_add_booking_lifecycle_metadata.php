<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('reschedule_count')->default(0);
            $table->timestamp('rescheduled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->string('cancelled_by', 32)->nullable();
            $table->text('no_show_reason')->nullable();
            $table->timestamp('no_show_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn([
                'reschedule_count',
                'rescheduled_at',
                'cancellation_reason',
                'cancelled_by',
                'no_show_reason',
                'no_show_at',
            ]);
        });
    }
};
