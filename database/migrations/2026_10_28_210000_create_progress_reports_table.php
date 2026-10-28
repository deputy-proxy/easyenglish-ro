<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('progress_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->string('title', 160);
            $table->string('overall_level', 32)->nullable();
            $table->text('summary');
            $table->text('strengths')->nullable();
            $table->text('areas_to_improve')->nullable();
            $table->text('recommendations')->nullable();
            $table->timestamp('shared_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['booking_id', 'created_at']);
            $table->index('shared_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('progress_reports');
    }
};
