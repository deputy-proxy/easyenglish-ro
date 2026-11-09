<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bundle_entitlements', function (Blueprint $table): void {
            $table->unsignedInteger('original_price_ron')->nullable()->after('paid_amount_ron');
            $table->json('discount_snapshot')->nullable()->after('original_price_ron');
        });
    }

    public function down(): void
    {
        Schema::table('bundle_entitlements', function (Blueprint $table): void {
            $table->dropColumn(['original_price_ron', 'discount_snapshot']);
        });
    }
};
