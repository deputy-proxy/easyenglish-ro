<section class="w-full">
    <flux:heading level="2" class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-settings.layout :heading="__('Profile')" :subheading="__('public.profile_settings_subheading')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                @if ($this->hasUnverifiedEmail)
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your email address is unverified.') }}

                            <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                {{ __('Click here to re-send the verification email.') }}
                            </flux:link>
                        </flux:text>

                    </div>
                @endif
            </div>

            <flux:separator />

            <div class="space-y-2">
                <flux:heading level="3">{{ __('public.profile_learning_details') }}</flux:heading>
                <flux:description>{{ __('public.profile_learning_details_help') }}</flux:description>
            </div>

            <flux:select wire:model="english_level" :label="__('public.field_level')">
                <option value="">{{ __('public.profile_level_not_set') }}</option>
                @foreach (['A1', 'A2', 'B1', 'B2', 'C1', 'C2'] as $level)
                    <option value="{{ $level }}">{{ __('public.level_' . strtolower($level)) }}</option>
                @endforeach
            </flux:select>
            <flux:description>{{ __('public.profile_level_self_reported_help') }}</flux:description>

            <flux:input wire:model="session_goal" :label="__('public.field_goal')" type="text" maxlength="500" />
            <flux:textarea wire:model="session_notes" :label="__('public.profile_learning_preferences')" rows="4" maxlength="5000" />
            <flux:description>{{ __('public.profile_learning_preferences_help') }}</flux:description>

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        </form>

        @if ($this->showDeleteUser)
            <livewire:settings.delete-user-form />
        @endif
    </x-settings.layout>
</section>
