<form method="post" action="{{ route('booking.submit') }}" class="space-y-5" data-free-session-form data-booking-async-form data-booking-verification-form data-contacts-verified="{{ $contactsVerified ? 'true' : 'false' }}" data-email-only="{{ config('services.contact_verification.mode', 'email_and_whatsapp') === 'email_only' ? 'true' : 'false' }}" data-verified-phone="{{ auth()->user()->phone }}" data-initial-eligible="{{ ($eligibility['eligible'] ?? false) ? 'true' : 'false' }}">
    @csrf
    @if($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" data-booking-form-errors role="alert" aria-live="assertive">
            @foreach($errors->all() as $error)<p class="m-0">{{ $error }}</p>@endforeach
        </div>
    @else
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" data-booking-form-errors role="alert" aria-live="assertive" hidden></div>
    @endif
    @if(session('status'))
        <p class="rounded-xl bg-primary-soft p-4 text-sm text-primary-strong" role="status">{{ session('status') }}</p>
    @endif
    @if($eligibility)
        <div class="rounded-xl p-4 text-sm leading-relaxed {{ $eligibility['eligible'] ? 'bg-surface-subtle text-muted' : 'border border-amber-200 bg-amber-50 text-amber-900' }}" role="status" aria-live="polite" data-free-session-eligibility="{{ $eligibility['status'] }}">
            {{ __($eligibility['eligible'] ? 'public.verification_eligible' : 'public.verification_already_used') }}
            @if(! $eligibility['eligible'])
                <a class="ml-1 font-semibold underline" href="#bundles">{{ __('public.verification_bundle_cta') }}</a>
            @endif
        </div>
    @endif
    @if(! $contactsVerified)
        <div class="rounded-xl bg-surface-subtle p-4 text-sm leading-relaxed text-muted">
            <strong class="text-ink">{{ __('public.verification_title') }}</strong>
            <p class="mt-1 mb-0">{{ __(config('services.contact_verification.mode', 'email_and_whatsapp') === 'email_only' ? 'public.verification_explainer_email_only' : 'public.verification_explainer') }}</p>
            <p class="mt-2 mb-0">{{ __('public.verification_email_status') }}: <strong>{{ auth()->user()->email_verified_at ? __('public.verification_status_verified') : __('public.verification_status_pending') }}</strong>
                @if(config('services.contact_verification.mode', 'email_and_whatsapp') !== 'email_only')<br>{{ __('public.verification_phone_status') }}: <strong>{{ $phoneAlreadyVerified ? __('public.verification_status_verified') : __('public.verification_status_pending') }}</strong>@endif
            </p>
        </div>
    @endif

    <input type="hidden" name="name" value="{{ auth()->user()->name }}">
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="grid gap-2">
            <label class="text-sm font-semibold text-ink" for="free-session-email">{{ __('public.field_email') }}</label>
            <input class="min-h-12 rounded-xl border border-line bg-surface-subtle px-4 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/15" id="free-session-email" name="email" type="email" value="{{ auth()->user()->email }}" autocomplete="email" readonly required maxlength="254">
        </div>
        <div class="grid gap-2">
            <label class="text-sm font-semibold text-ink" for="free-session-phone">{{ __('public.field_phone') }}</label>
            <input class="min-h-12 rounded-xl border border-line bg-white px-4 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/15" id="free-session-phone" name="phone" type="tel" value="{{ old('phone', $bookingFormData['phone'] ?? $verification?->customer_phone ?? auth()->user()->phone) }}" autocomplete="tel" required maxlength="32" pattern="[+0-9 () .-]{7,32}">
        </div>
    </div>
    @if(! auth()->user()->english_level)
        <div class="grid gap-2">
            <label class="text-sm font-semibold text-ink" for="free-session-level">{{ __('public.field_level') }}</label>
            <select class="min-h-12 rounded-xl border border-line bg-white px-4 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/15" id="free-session-level" name="english_level" required>
                <option value="">{{ __('public.field_level_placeholder') }}</option>
                @foreach(['A1','A2','B1','B2','C1','C2'] as $level)
                    <option value="{{ $level }}" @selected(old('english_level', $bookingFormData['english_level'] ?? null) === $level)>{{ __('public.level_'.strtolower($level)) }}</option>
                @endforeach
            </select>
        </div>
    @else
        <input type="hidden" name="english_level" value="{{ auth()->user()->english_level }}">
    @endif
    @if(! auth()->user()->session_goal)
        <div class="grid gap-2">
            <label class="text-sm font-semibold text-ink" for="free-session-goal">{{ __('public.field_goal') }}</label>
            <input class="min-h-12 rounded-xl border border-line bg-white px-4 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/15" id="free-session-goal" name="session_goal" value="{{ old('session_goal', $bookingFormData['session_goal'] ?? '') }}" placeholder="{{ __('public.field_goal_placeholder') }}" required maxlength="500">
        </div>
    @else
        <input type="hidden" name="session_goal" value="{{ auth()->user()->session_goal }}">
    @endif
    @if(! auth()->user()->english_level)
        <div class="grid gap-2">
            <label class="text-sm font-semibold text-ink" for="free-session-notes">{{ __('public.field_notes') }}</label>
            <textarea class="min-h-28 rounded-xl border border-line bg-white px-4 py-3 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/15" id="free-session-notes" name="notes" maxlength="5000" placeholder="{{ __('public.field_notes_placeholder') }}">{{ old('notes', $bookingFormData['notes'] ?? auth()->user()->session_notes) }}</textarea>
        </div>
    @else
        <input type="hidden" name="notes" value="{{ auth()->user()->session_notes }}">
    @endif
    <div class="rounded-xl bg-surface-subtle p-4 text-xs leading-relaxed text-muted">
        {{ __('public.policy_summary') }} <a class="font-semibold text-primary underline" href="{{ route('policy','terms') }}">{{ __('public.footer_terms') }}</a> · <a class="font-semibold text-primary underline" href="{{ route('policy','privacy') }}">{{ __('public.footer_privacy') }}</a>
    </div>
    <label class="flex items-start gap-3 text-sm leading-relaxed text-muted">
        <input class="mt-1 size-4 shrink-0 accent-primary" type="checkbox" name="accepted_policy" value="1" required @checked(old('accepted_policy', $bookingFormData['accepted_policy'] ?? false))>
        <span>{{ __('public.policy_accept') }}</span>
    </label>
    @if(config('services.contact_verification.mode', 'email_and_whatsapp') !== 'email_only' && ! $phoneAlreadyVerified && ! $whatsappConsent)
        <label class="flex items-start gap-3 text-sm leading-relaxed text-muted">
            <input class="mt-1 size-4 shrink-0 accent-primary" type="checkbox" name="whatsapp_service_consent" value="1" required @checked(old('whatsapp_service_consent', $bookingFormData['whatsapp_service_consent'] ?? false))>
            <span>{{ __('public.whatsapp_service_consent') }}</span>
        </label>
    @endif
    <input type="hidden" name="locale" value="{{ app()->getLocale() }}">
    <input type="hidden" name="type" value="intro">
    <input type="hidden" name="return_to" value="booking">
    <input type="hidden" name="starts_at" value="{{ $bookingSlot }}" data-booking-starts-at required>
    @if($verification)<input type="hidden" name="verification_id" value="{{ $verification->id }}">@endif
    @if($contactsVerified && config('services.contact_verification.mode', 'email_and_whatsapp') !== 'email_only')
        <div class="hidden space-y-4 rounded-xl bg-surface-subtle p-4" data-phone-change-controls>
            <p class="m-0 text-sm text-muted">{{ __('public.verification_phone_changed') }}</p>
            <label class="flex items-start gap-3 text-sm leading-relaxed text-muted">
                <input class="mt-1 size-4 shrink-0 accent-primary" type="checkbox" name="whatsapp_service_consent" value="1" data-phone-change-consent>
                <span>{{ __('public.whatsapp_service_consent') }}</span>
            </label>
            <button class="inline-flex min-h-12 w-full items-center justify-center rounded-full bg-primary px-6 py-3 text-sm font-bold text-white transition hover:bg-primary-hover" type="submit" formaction="{{ route('verification.start') }}" formmethod="post">{{ __('public.verification_send_button_remaining') }}</button>
        </div>
    @endif
    @if(! $contactsVerified)
        <button class="inline-flex min-h-12 w-full items-center justify-center rounded-full bg-primary px-6 py-3 text-sm font-bold text-white transition hover:bg-primary-hover" type="submit" formaction="{{ route('verification.start') }}" formmethod="post">
            {{ __(config('services.contact_verification.mode', 'email_and_whatsapp') === 'email_only' ? 'public.verification_send_button_email_only' : (($phoneAlreadyVerified || auth()->user()->email_verified_at) ? 'public.verification_send_button_remaining' : 'public.verification_send_button')) }}
        </button>
        @if($verification && ! $verification->email_verified_at)
            <div class="grid gap-2">
                <label class="text-sm font-semibold text-ink" for="free-session-email-code">{{ __('public.verification_email_code_label') }}</label>
                <input class="min-h-12 rounded-xl border border-line bg-white px-4 text-sm text-ink" id="free-session-email-code" name="email_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="123456">
            </div>
            <button class="inline-flex min-h-12 w-full items-center justify-center rounded-full border border-primary px-6 py-3 text-sm font-bold text-primary" type="submit" formaction="{{ route('verification.email-code.confirm') }}" formmethod="post">{{ __('public.verification_confirm_email_button') }}</button>
        @endif
        @if(config('services.contact_verification.mode', 'email_and_whatsapp') !== 'email_only' && $verification && ! $verification->phone_verified_at)
            <div class="grid gap-2">
                <label class="text-sm font-semibold text-ink" for="free-session-phone-code">{{ __('public.verification_code_label') }}</label>
                <input class="min-h-12 rounded-xl border border-line bg-white px-4 text-sm text-ink" id="free-session-phone-code" name="phone_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="123456">
            </div>
            <button class="inline-flex min-h-12 w-full items-center justify-center rounded-full border border-primary px-6 py-3 text-sm font-bold text-primary" type="submit" formaction="{{ route('verification.phone.confirm') }}" formmethod="post">{{ __('public.verification_confirm_phone_button') }}</button>
        @endif
    @endif
    @if($contactsVerified && (($eligibility['eligible'] ?? false) || config('services.contact_verification.mode', 'email_and_whatsapp') === 'email_only'))
        <button class="inline-flex min-h-12 w-full items-center justify-center rounded-full bg-primary px-6 py-3 text-sm font-bold text-white transition hover:bg-primary-hover" type="submit" data-booking-final-submit @if(! ($eligibility['eligible'] ?? false)) hidden @endif>{{ __('public.booking_submit_intro') }}</button>
    @elseif(! $contactsVerified)
        <p class="m-0 text-xs leading-relaxed text-muted">{{ __(config('services.contact_verification.mode', 'email_and_whatsapp') === 'email_only' ? 'public.verification_required_email_only' : 'public.verification_required') }}</p>
    @endif
</form>
