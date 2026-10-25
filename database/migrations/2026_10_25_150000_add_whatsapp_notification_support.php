<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_contacts', function (Blueprint $table): void {
            $table->id();
            $table->string('phone', 20)->unique();
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('opted_out_at')->nullable();
            $table->string('consent_source', 32)->nullable();
            $table->string('opt_out_source', 32)->nullable();
            $table->timestamps();
            $table->index(['consented_at', 'opted_out_at']);
        });

        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->string('provider_message_id', 255)->nullable()->unique();
            $table->string('provider_status', 32)->nullable();
            $table->string('provider_error_code', 64)->nullable();
        });

        Schema::create('whatsapp_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 64)->unique();
            $table->string('event_type', 32);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_webhook_events');
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropUnique(['provider_message_id']);
            $table->dropColumn(['provider_message_id', 'provider_status', 'provider_error_code']);
        });
        Schema::dropIfExists('whatsapp_contacts');
    }
};
