<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreignId('customer_user_id')->nullable()->after('public_reference')->constrained('users')->nullOnDelete();
            $table->index(['customer_user_id', 'starts_at'], 'bookings_customer_start_idx');
        });

        Schema::table('bundle_entitlements', function (Blueprint $table): void {
            $table->foreignId('customer_user_id')->nullable()->after('bundle_definition_id')->constrained('users')->nullOnDelete();
            $table->index(['customer_user_id', 'status', 'expires_at'], 'bundle_customer_status_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bundle_entitlements', function (Blueprint $table): void {
            $table->dropIndex('bundle_customer_status_expiry_idx');
            $table->dropConstrainedForeignId('customer_user_id');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex('bookings_customer_start_idx');
            $table->dropConstrainedForeignId('customer_user_id');
        });
    }
};
