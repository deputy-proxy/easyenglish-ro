<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->timestamp('customer_ownership_revoked_at')->nullable()->after('customer_user_id');
            $table->index('customer_ownership_revoked_at');
        });

        Schema::table('bundle_entitlements', function (Blueprint $table): void {
            $table->timestamp('customer_ownership_revoked_at')->nullable()->after('customer_user_id');
            $table->index('customer_ownership_revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('bundle_entitlements', function (Blueprint $table): void {
            $table->dropIndex(['customer_ownership_revoked_at']);
            $table->dropColumn('customer_ownership_revoked_at');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['customer_ownership_revoked_at']);
            $table->dropColumn('customer_ownership_revoked_at');
        });
    }
};
