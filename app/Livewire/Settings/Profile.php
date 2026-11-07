<?php

namespace App\Livewire\Settings;

use App\Concerns\ProfileValidationRules;
use Flux\Flux;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.dashboard-settings')]
#[Title('Profile settings')]
class Profile extends Component
{
    use ProfileValidationRules;

    public string $name = '';

    public string $email = '';

    public string $english_level = '';

    public string $session_goal = '';

    public string $session_notes = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
        $studentProfile = Auth::user()->studentProfile;
        $this->english_level = $studentProfile->english_level ?? '';
        $this->session_goal = $studentProfile->learning_goal ?? '';
        $this->session_notes = $studentProfile->notes ?? '';
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            ...$this->profileRules($user->id),
            'english_level' => ['nullable', 'in:A1,A2,B1,B2,C1,C2'],
            'session_goal' => ['nullable', 'string', 'max:500'],
            'session_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $validated['english_level'] = filled($validated['english_level']) ? $validated['english_level'] : null;
        $validated['session_goal'] = filled($validated['session_goal']) ? trim($validated['session_goal']) : null;
        $validated['session_notes'] = filled($validated['session_notes']) ? trim($validated['session_notes']) : null;

        $studentProfileData = [
            'english_level' => $validated['english_level'],
            'learning_goal' => $validated['session_goal'],
            'notes' => $validated['session_notes'],
        ];
        unset($validated['english_level'], $validated['session_goal'], $validated['session_notes']);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();
        if (! $user->is_teacher && ! $user->is_staff) {
            $user->studentProfile()->updateOrCreate([], $studentProfileData);
        }

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Flux::toast(text: __('A new verification link has been sent to your email address.'));
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        $user = Auth::user();

        return $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        $user = Auth::user();

        return ! $user instanceof MustVerifyEmail || $user->hasVerifiedEmail();
    }
}
