<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('provider_charge_id')->nullable()->after('provider_payment_id');
            $table->index('provider_charge_id');
        });

        Schema::create('payment_disputes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('provider_dispute_id')->unique();
            $table->string('provider_payment_intent_id')->nullable();
            $table->string('provider_charge_id')->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 32);
            $table->string('reason', 64)->nullable();
            $table->timestamp('evidence_due_at')->nullable();
            $table->timestamp('created_at_provider')->nullable();
            $table->timestamp('closed_at_provider')->nullable();
            $table->string('last_event_id')->nullable();
            $table->timestamps();

            $table->index(['payment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_disputes');

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['provider_charge_id']);
            $table->dropColumn('provider_charge_id');
        });
    }
};
