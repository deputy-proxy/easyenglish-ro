<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'phone' => ['required', 'string', 'max:32', 'regex:/^\\+?[0-9\\s().-]{7,32}$/'],
            'english_level' => ['required', 'in:A1,A2,B1,B2,C1,C2'],
            'session_goal' => ['required', 'string', 'max:500'],
            'session_notes' => ['nullable', 'string', 'max:5000'],
            'password' => $this->passwordRules(),
        ])->validate();

        return User::create([
            'name' => $input['name'],
            'email' => mb_strtolower(trim($input['email'])),
            'password' => $input['password'],
            'phone' => PhoneNumber::normalize($input['phone']),
            'english_level' => $input['english_level'],
            'session_goal' => trim($input['session_goal']),
            'session_notes' => $input['session_notes'] ?? null,
        ]);
    }
}
