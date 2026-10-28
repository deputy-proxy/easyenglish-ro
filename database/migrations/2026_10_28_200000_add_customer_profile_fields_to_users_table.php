<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 32)->nullable()->after('email')->index();
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
            $table->string('english_level', 2)->nullable();
            $table->string('session_goal', 500)->nullable();
            $table->text('session_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['phone']);
            $table->dropColumn(['phone', 'phone_verified_at', 'english_level', 'session_goal', 'session_notes']);
        });
    }
};
