<x-layouts::auth :title="__('Register')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Create an account')" :description="__('Enter your details below to create your account')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Name -->
            <flux:input
                name="name"
                :label="__('Name')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('Full name')"
            />

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
            />

            <flux:input
                name="phone"
                :label="__('public.field_phone')"
                :value="old('phone')"
                type="tel"
                required
                autocomplete="tel"
                placeholder="+40 722 123 456"
            />

            <flux:select name="english_level" :label="__('public.field_level')" required>
                <option value="">{{ __('public.field_level_placeholder') }}</option>
                @foreach (['A1', 'A2', 'B1', 'B2', 'C1', 'C2'] as $level)
                    <option value="{{ $level }}" @selected(old('english_level') === $level)>{{ __('public.level_'.strtolower($level)) }}</option>
                @endforeach
            </flux:select>

            <flux:input
                name="session_goal"
                :label="__('public.field_goal')"
                :value="old('session_goal')"
                type="text"
                required
                maxlength="500"
                :placeholder="__('public.field_goal_placeholder')"
            />

            <flux:textarea
                name="session_notes"
                :label="__('public.field_notes')"
                :value="old('session_notes')"
                maxlength="5000"
                :placeholder="__('public.field_notes_placeholder')"
            />

            <!-- Password -->
            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <!-- Confirm Password -->
            <flux:input
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                    {{ __('Create account') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
