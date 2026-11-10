<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_verification_challenges', function (Blueprint $table): void {
            if (! Schema::hasColumn('contact_verification_challenges', 'phone_provider_message_id')) {
                $table->string('phone_provider_message_id', 191)->nullable()->index();
            }
            if (! Schema::hasColumn('contact_verification_challenges', 'phone_provider_status')) {
                $table->string('phone_provider_status', 32)->nullable();
            }
            if (! Schema::hasColumn('contact_verification_challenges', 'phone_provider_error_code')) {
                $table->string('phone_provider_error_code', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('contact_verification_challenges', function (Blueprint $table): void {
            if (Schema::hasColumn('contact_verification_challenges', 'phone_provider_message_id')) {
                $table->dropIndex(['phone_provider_message_id']);
                $table->dropColumn('phone_provider_message_id');
            }
            if (Schema::hasColumn('contact_verification_challenges', 'phone_provider_status')) {
                $table->dropColumn('phone_provider_status');
            }
            if (Schema::hasColumn('contact_verification_challenges', 'phone_provider_error_code')) {
                $table->dropColumn('phone_provider_error_code');
            }
        });
    }
};
