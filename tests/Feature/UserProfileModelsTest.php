<?php

use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;

test('student accounts have a separate student profile', function () {
    $user = User::factory()->create(['english_level' => 'B2', 'session_goal' => 'Work presentations', 'session_notes' => 'Role-play']);

    expect($user->studentProfile)->toBeInstanceOf(StudentProfile::class)
        ->and($user->studentProfile->english_level)->toBe('B2')
        ->and($user->studentProfile->learning_goal)->toBe('Work presentations')
        ->and($user->studentProfile->notes)->toBe('Role-play');

    $this->assertDatabaseMissing('users', ['id' => $user->id, 'english_level' => 'B2']);
});

test('teacher accounts have a separate teacher profile', function () {
    $teacher = User::factory()->create([
        'is_teacher' => true,
        'teacher_photo_path' => 'teachers/profile.jpg',
        'teacher_bio' => 'Business English teacher',
    ]);

    expect($teacher->teacherProfile)->toBeInstanceOf(TeacherProfile::class)
        ->and($teacher->teacherProfile->photo_path)->toBe('teachers/profile.jpg')
        ->and($teacher->teacherProfile->bio)->toBe('Business English teacher');

    $this->assertDatabaseMissing('users', ['id' => $teacher->id, 'teacher_bio' => 'Business English teacher']);
});
