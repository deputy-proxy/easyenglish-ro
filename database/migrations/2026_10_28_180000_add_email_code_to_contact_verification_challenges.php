<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('contact_verification_challenges', 'email_code_hash') || ! Schema::hasColumn('contact_verification_challenges', 'email_attempts')) {
            Schema::table('contact_verification_challenges', function (Blueprint $table): void {
            if (! Schema::hasColumn('contact_verification_challenges', 'email_code_hash')) {
                $table->char('email_code_hash', 64)->nullable()->after('email_token_hash');
            }
            if (! Schema::hasColumn('contact_verification_challenges', 'email_attempts')) {
                $table->unsignedTinyInteger('email_attempts')->default(0)->after('phone_attempts');
            }
            });
        }
    }

    public function down(): void
    {
        Schema::table('contact_verification_challenges', function (Blueprint $table): void {
            if (Schema::hasColumn('contact_verification_challenges', 'email_code_hash')) {
                $table->dropColumn('email_code_hash');
            }
            if (Schema::hasColumn('contact_verification_challenges', 'email_attempts')) {
                $table->dropColumn('email_attempts');
            }
        });
    }
};
