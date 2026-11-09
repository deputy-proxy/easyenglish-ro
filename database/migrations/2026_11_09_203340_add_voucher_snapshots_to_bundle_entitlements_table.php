<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bundle_entitlements', function (Blueprint $table): void {
            $table->string('voucher_code_snapshot', 64)->nullable()->after('discount_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('bundle_entitlements', function (Blueprint $table): void {
            $table->dropColumn('voucher_code_snapshot');
        });
    }
};
