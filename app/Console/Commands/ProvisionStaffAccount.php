<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ProvisionStaffAccount extends Command
{
    protected $signature = 'staff:provision {email : Email address for the staff account} {--name= : Name for a new account}';

    protected $description = 'Provision a staff account through an interactive, non-public operator workflow';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $name = trim((string) ($this->option('name') ?? ''));

        try {
            Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:254']])->validate();
            if ($name !== '') {
                Validator::make(['name' => $name], ['name' => ['string', 'max:160']])->validate();
            }
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();
        if ($user?->is_staff) {
            $this->info('This account is already provisioned as staff.');

            return self::SUCCESS;
        }

        if (! $user && $name === '') {
            $name = trim((string) $this->ask('Staff member name'));
        }

        if ($name === '' && ! $user) {
            $this->error('A non-empty name is required to create a staff account.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Set an initial password (minimum 12 characters)');
        $confirmation = (string) $this->secret('Confirm initial password');
        try {
            Validator::make(['password' => $password, 'confirmation' => $confirmation], [
                'password' => ['required', 'string', 'same:confirmation', Password::min(12)->letters()->numbers()],
                'confirmation' => ['required', 'string'],
            ])->validate();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        if ($user) {
            $user->forceFill([
                'is_staff' => true,
                'email_verified_at' => $user->email_verified_at ?? now(),
                'password' => Hash::make($password),
            ])->save();
        } else {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
            ]);
            $user->forceFill(['is_staff' => true, 'email_verified_at' => now()])->save();
        }

        $this->info("Staff account provisioned for {$email}. No password was displayed or logged.");

        return self::SUCCESS;
    }
}
