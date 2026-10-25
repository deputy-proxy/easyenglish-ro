<?php

test('public account registration is disabled for the staff-operated booking product', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Untrusted Visitor',
        'email' => 'visitor@example.com',
        'password' => 'StrongPassword123!',
        'password_confirmation' => 'StrongPassword123!',
    ])->assertNotFound();

    $this->assertDatabaseMissing('users', ['email' => 'visitor@example.com']);
});
