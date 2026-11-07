<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->string('provider', 24)->nullable()->after('channel')->index();
            $table->json('provider_attempts')->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropIndex(['provider']);
            $table->dropColumn(['provider', 'provider_attempts']);
        });
    }
};
