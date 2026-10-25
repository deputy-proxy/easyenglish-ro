<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('locale', 5)->default('ro');
        });

        Schema::table('bundle_entitlements', function (Blueprint $table): void {
            $table->string('locale', 5)->default('ro');
        });

        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->foreignId('payment_refund_id')->nullable()->after('bundle_entitlement_id')->constrained('payment_refunds')->nullOnDelete();
            $table->json('metadata')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_refund_id');
            $table->dropColumn('metadata');
        });

        Schema::table('bundle_entitlements', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};
