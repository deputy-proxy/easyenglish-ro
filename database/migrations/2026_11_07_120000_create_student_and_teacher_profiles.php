<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('english_level', 2)->nullable();
            $table->string('learning_goal', 500)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('teacher_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('photo_path')->nullable();
            $table->text('bio')->nullable();
            $table->timestamps();
        });

        DB::table('users')->where('is_teacher', true)->orderBy('id')->each(function (object $user): void {
            DB::table('teacher_profiles')->insert([
                'user_id' => $user->id, 'photo_path' => $user->teacher_photo_path,
                'bio' => $user->teacher_bio, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        DB::table('users')->where('is_teacher', false)->where('is_staff', false)->orderBy('id')->each(function (object $user): void {
            DB::table('student_profiles')->insert([
                'user_id' => $user->id, 'english_level' => $user->english_level,
                'learning_goal' => $user->session_goal, 'notes' => $user->session_notes,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['english_level', 'session_goal', 'session_notes', 'teacher_photo_path', 'teacher_bio']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('english_level', 2)->nullable();
            $table->string('session_goal', 500)->nullable();
            $table->text('session_notes')->nullable();
            $table->string('teacher_photo_path')->nullable();
            $table->text('teacher_bio')->nullable();
        });
        foreach (DB::table('student_profiles')->get() as $profile) {
            DB::table('users')->where('id', $profile->user_id)->update([
                'english_level' => $profile->english_level, 'session_goal' => $profile->learning_goal, 'session_notes' => $profile->notes,
            ]);
        }
        foreach (DB::table('teacher_profiles')->get() as $profile) {
            DB::table('users')->where('id', $profile->user_id)->update([
                'teacher_photo_path' => $profile->photo_path, 'teacher_bio' => $profile->bio,
            ]);
        }
        Schema::dropIfExists('teacher_profiles');
        Schema::dropIfExists('student_profiles');
    }
};
