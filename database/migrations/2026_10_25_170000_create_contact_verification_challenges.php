<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contact_verification_challenges')) {
            Schema::create('contact_verification_challenges', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('customer_email', 254)->index();
                $table->string('customer_phone', 32)->index();
                $table->string('locale', 2)->default('ro');
                $table->string('return_to', 16)->default('booking');
                $table->foreignId('return_bundle_id')->nullable()->constrained('bundle_definitions')->nullOnDelete();
                $table->char('email_token_hash', 64);
                $table->char('phone_code_hash', 64);
                $table->timestamp('email_verified_at')->nullable();
                $table->timestamp('phone_verified_at')->nullable();
                $table->timestamp('expires_at')->index();
                $table->timestamp('email_sent_at')->nullable();
                $table->timestamp('phone_sent_at')->nullable();
                $table->unsignedTinyInteger('phone_attempts')->default(0);
                $table->timestamp('consumed_at')->nullable();
                $table->timestamps();
            });
        }

        // MySQL limits identifier names to 64 characters. Use a short explicit
        // name, and tolerate a table left behind by a failed earlier migration.
        if (! Schema::hasIndex('contact_verification_challenges', 'contact_verification_lookup_idx')) {
            Schema::table('contact_verification_challenges', function (Blueprint $table): void {
                $table->index(
                    ['customer_email', 'customer_phone', 'expires_at'],
                    'contact_verification_lookup_idx',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_verification_challenges');
    }
};
