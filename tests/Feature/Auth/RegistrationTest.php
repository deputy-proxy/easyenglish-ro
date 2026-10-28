<?php

test('customers can register and are returned to the selected customer journey', function () {
    $this->get('/register')->assertOk()->assertSee('Create an account');

    $this->from('/book?type=intro&slot=2026-10-29T09%3A00%3A00%2B02%3A00')->post('/register', [
        'name' => 'New Learner',
        'email' => 'new-learner@example.com',
        'phone' => '+40722123456',
        'english_level' => 'B1',
        'session_goal' => 'Work conversations',
        'session_notes' => 'Prepare for meetings',
        'password' => 'StrongPassword123!',
        'password_confirmation' => 'StrongPassword123!',
    ])->assertRedirect();

    $this->assertDatabaseHas('users', ['email' => 'new-learner@example.com', 'name' => 'New Learner', 'phone' => '+40722123456', 'english_level' => 'B1', 'session_goal' => 'Work conversations']);
    $this->assertAuthenticated();
});
