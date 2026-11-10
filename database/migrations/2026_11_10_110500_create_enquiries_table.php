<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enquiries', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 254)->index();
            $table->string('subject', 180);
            $table->text('message');
            $table->string('status', 24)->default('new')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enquiries');
    }
};
