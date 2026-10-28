<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('metric_key', 40);
            $table->unsignedTinyInteger('baseline_score')->nullable();
            $table->unsignedTinyInteger('current_score')->nullable();
            $table->unsignedTinyInteger('potential_score')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamps();
            $table->unique(['booking_id', 'metric_key']);
            $table->index(['metric_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_feedback');
    }
};
