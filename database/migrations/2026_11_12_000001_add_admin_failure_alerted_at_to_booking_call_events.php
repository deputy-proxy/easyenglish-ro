<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_call_events', function (Blueprint $table): void {
            $table->timestamp('admin_failure_alerted_at')->nullable()->after('last_error_message');
        });
    }

    public function down(): void
    {
        Schema::table('booking_call_events', function (Blueprint $table): void {
            $table->dropColumn('admin_failure_alerted_at');
        });
    }
};
