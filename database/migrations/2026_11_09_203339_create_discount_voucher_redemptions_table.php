<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_voucher_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('discount_voucher_id')->constrained()->restrictOnDelete();
            $table->foreignId('bundle_entitlement_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('reserved')->index();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();
            $table->index(['discount_voucher_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_voucher_redemptions');
    }
};
