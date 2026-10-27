<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_message_id', 255)->unique();
            $table->string('channel', 24)->default('whatsapp')->index();
            $table->string('direction', 16)->default('inbound')->index();
            $table->string('from_phone', 32)->index();
            $table->string('to_phone', 32)->nullable()->index();
            $table->string('type', 32)->default('unknown')->index();
            $table->longText('body')->nullable();
            $table->string('provider_timestamp', 32)->nullable();
            $table->timestamp('received_at')->nullable()->index();
            $table->json('context')->nullable();
            $table->timestamps();
            $table->index(['channel', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
