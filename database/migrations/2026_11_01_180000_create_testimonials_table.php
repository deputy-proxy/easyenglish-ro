<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('testimonials', function (Blueprint $table): void {
            $table->id();
            $table->text('quote');
            $table->string('author_name', 120)->nullable();
            $table->string('author_context', 160)->nullable();
            $table->string('locale', 2)->default('ro');
            $table->timestamp('publication_consent_at')->nullable();
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['locale', 'is_published', 'publication_consent_at', 'sort_order'], 'testimonials_public_listing_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('testimonials');
    }
};
