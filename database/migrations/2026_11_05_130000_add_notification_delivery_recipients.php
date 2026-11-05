<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->string('recipient_type', 20)->default('customer')->after('booking_id');
            $table->foreignId('recipient_user_id')->nullable()->after('recipient_type')->constrained('users')->nullOnDelete();
            $table->foreignId('booking_call_event_id')->nullable()->after('recipient_user_id')->constrained('booking_call_events')->nullOnDelete();
            $table->index(['recipient_type', 'recipient_user_id'], 'notification_deliveries_recipient_index');
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropIndex('notification_deliveries_recipient_index');
            $table->dropConstrainedForeignId('booking_call_event_id');
            $table->dropConstrainedForeignId('recipient_user_id');
            $table->dropColumn('recipient_type');
        });
    }
};
