@props([
    'bookingState', 'weekStart', 'contactsVerified' => false, 'verification' => null,
    'eligibility' => null, 'phoneAlreadyVerified' => false, 'whatsappConsent' => false,
    'bookingFormData' => [], 'bundles' => collect(), 'compact' => false, 'showBookingModal' => true,
    'selectionMode' => 'modal', 'bookingUrl' => null,
])

@if($bookingState['show_calendar'])
        <section class="mx-auto w-[calc(100%-2.5rem)] max-w-[1160px] py-14 md:py-[82px]" id="availability" data-mobile-booking-cta-blocker>
            <div class="mb-8 max-w-[650px] {{ $compact ? 'mx-auto text-center' : '' }}">
                <p class="mb-[18px] type-eyebrow" data-reveal>{{ __('public.availability_eyebrow') }}</p>
                <h2 class="type-section-title mb-3.5" data-reveal>{{ $bookingState['state'] === 'customer_free_session_available' && $bookingState['display_name'] ? __('public.booking_personalized_title', ['name' => $bookingState['display_name']]) : ($compact ? __('public.booking_landing_title') : __('public.availability_title')) }}</h2>
                <p class="type-section-copy" data-reveal>{{ $compact ? __('public.booking_landing_text') : __('public.availability_text') }}</p>
            </div>
            <div
                class="relative"
                data-availability
                data-week="{{ $weekStart->format('Y-m-d') }}"
                data-locale="{{ app()->getLocale() }}"
                data-initial-slot="{{ request('slot', old('starts_at', '')) }}"
                data-booking-enabled="{{ $bookingState['booking_enabled'] ? 'true' : 'false' }}"
                data-selection-mode="{{ $selectionMode }}"
                data-book-url="{{ $bookingUrl ?? route('booking.create') }}"
            >
                @if(! $bookingState['booking_enabled'])
                    <p class="mb-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">{{ __('public.booking_internal_disabled') }}</p>
                @endif
                <div data-availability-content>
                    <div class="overflow-hidden rounded-[22px] bg-white p-3 shadow-[0_1px_2px_rgba(0,0,0,0.07),0_2px_4px_rgba(0,0,0,0.07),0_4px_8px_rgba(0,0,0,0.07),0_8px_16px_rgba(0,0,0,0.07),0_16px_32px_rgba(0,0,0,0.07),0_32px_64px_rgba(0,0,0,0.07)] sm:p-6" data-reveal>
                        <div class="mb-3 flex items-center justify-between gap-3 sm:mb-5">
                            <p class="min-h-5 min-w-0 whitespace-nowrap text-[10px] font-semibold leading-5 text-primary-strong sm:text-xs" data-week-status aria-live="polite">{{ __('public.availability_loading') }}</p>
                            <div class="flex shrink-0 items-center gap-2" data-availability-navigation>
                                <button class="testimonial-arrow" type="button" data-prev aria-label="{{ __('public.previous_week') }}">
                                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5m7 7-7-7 7-7" /></svg>
                                </button>
                                <button class="testimonial-arrow testimonial-arrow--next" type="button" data-next aria-label="{{ __('public.next_week') }}">
                                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-7-7 7 7-7 7" /></svg>
                                </button>
                            </div>
                        </div>
                        <div class="hidden min-w-0 grid-cols-7 gap-1 sm:grid sm:gap-2" data-weekgrid aria-live="polite">
                            <p class="col-span-7">{{ __('public.availability_loading') }}</p>
                        </div>
                        <div class="grid gap-2 sm:hidden" data-weekgrid-mobile aria-live="polite">
                            <p>{{ __('public.availability_loading') }}</p>
                        </div>
                        <p class="mt-3 text-[11px] leading-relaxed text-muted sm:mt-3.5 sm:text-xs">{{ __('public.timezone_note') }}</p>
                    </div>

                </div>

                @if($showBookingModal)
                <div
                    class="invisible pointer-events-none absolute inset-x-0 top-0 rounded-[22px] bg-white p-5 shadow-[0_1px_2px_rgba(0,0,0,0.07),0_2px_4px_rgba(0,0,0,0.07),0_4px_8px_rgba(0,0,0,0.07),0_8px_16px_rgba(0,0,0,0.07),0_16px_32px_rgba(0,0,0,0.07),0_32px_64px_rgba(0,0,0,0.07)] sm:p-7"
                    data-booking-form-panel
                    data-booking-submitting-message="{{ __('public.booking_auth_submitting') }}"
                    data-booking-network-error="{{ __('public.booking_auth_network_error') }}"
                    data-booking-server-error="{{ __('public.booking_auth_server_error') }}"
                    data-booking-session-expired="{{ __('public.booking_auth_session_expired') }}"
                    data-booking-rate-limited="{{ __('public.booking_auth_rate_limited') }}"
                    data-booking-two-factor-message="{{ __('public.booking_auth_two_factor_message') }}"
                    data-booking-phone-changed-message="{{ __('public.verification_phone_changed') }}"
                    data-booking-phone-changed-email-only-message="{{ __('public.verification_phone_changed_email_only') }}"
                    aria-hidden="true"
                    aria-labelledby="free-session-form-title"
                >
                    <div class="mb-6 flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <h3 id="free-session-form-title" class="type-card-title m-0 text-ink">{{ __('public.free_booking_details') }}</h3>
                            <p class="mt-2 mb-0 text-sm font-semibold text-primary-strong"><span>{{ __('public.booking_selected_slot') }}:</span> <span data-booking-slot-label></span></p>
                        </div>
                        <button class="testimonial-arrow shrink-0" type="button" data-booking-form-close aria-label="{{ __('public.booking_close_form') }}">
                            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="m18 6-12 12M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <div data-booking-feedback class="mb-4 rounded-xl bg-surface-subtle p-4 text-sm text-muted" role="status" aria-live="polite" aria-atomic="true" hidden></div>
                    <div data-booking-step-content>
                    @if(auth()->check() && $bookingState['booking_enabled'])
                        @include('public.partials.booking-authenticated-form', ['bookingSlot' => request('slot', old('starts_at', ''))])
                    @elseif(! auth()->check())
                        @if($errors->any())
                            <div class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
                                @foreach($errors->all() as $error)<p class="m-0">{{ $error }}</p>@endforeach
                            </div>
                        @endif
                        @if(session('status'))
                            <p class="mb-5 rounded-xl bg-primary-soft p-4 text-sm text-primary-strong" role="status">{{ session('status') }}</p>
                        @endif
                        <div class="mb-5 flex flex-wrap items-center gap-2 text-sm text-muted">
                            <span>{{ __('public.booking_account_question') }}</span>
                            <button class="font-bold text-primary underline" type="button" data-booking-branch-toggle="login">{{ __('public.booking_existing_account') }}</button>
                            <button class="hidden font-bold text-primary underline" type="button" data-booking-branch-toggle="register">{{ __('public.booking_new_account') }}</button>
                        </div>
                        <form method="post" action="{{ route('booking.register') }}" data-booking-endpoint="{{ route('booking.register') }}" class="space-y-5" data-booking-register-branch data-booking-async-form data-booking-auth-form>
                            @csrf
                            <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" data-booking-form-errors role="alert" aria-live="assertive" hidden></div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="grid gap-2">
                                    <label class="text-sm font-semibold text-ink" for="free-session-name">{{ __('public.field_name') }}</label>
                                    <input class="min-h-12 rounded-xl border border-line bg-white px-4 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/15" id="free-session-name" name="name" value="{{ old('name') }}" autocomplete="name" required maxlength="255">
                                </div>
                                <div class="grid gap-2">
                                    <label class="text-sm font-semibold text-ink" for="free-session-email">{{ __('public.field_email') }}</label>
                                    <input class="min-h-12 rounded-xl border border-line bg-white px-4 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/15" id="free-session-email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required maxlength="255">
                                </div>
                                <div class="grid gap-2">
                                    <label class="text-sm font-semibold text-ink" for="free-session-phone">{{ __('public.field_phone') }}</label>
                                    <input class="min-h-12 rounded-xl border border-line bg-white px-4 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/15" id="free-session-phone" name="phone" type="tel" value="{{ old('phone') }}" autocomplete="tel" required maxlength="32" pattern="\+?([0-9]|\s|\(|\)|\.|-){7,32}">
                                </div>
                                <div class="grid gap-2">
                                    <label class="text-sm font-semibold text-ink" for="free-session-level">{{ __('public.field_level') }}</label>
                                    <select class="min-h-12 rounded-xl border border-line bg-white px-4 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/15" id="free-session-level" name="english_level" required>
                                        <option value="">{{ __('public.field_level_placeholder') }}</option>
                                        @foreach(['A1','A2','B1','B2','C1','C2'] as $level)
                                            <option value="{{ $level }}" @selected(old('english_level') === $level)>{{ __('public.level_'.strtolower($level)) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="grid gap-2">
                                <label class="text-sm font-semibold text-ink" for="free-session-goal">{{ __('public.field_goal') }}</label>
                                <input class="min-h-12 rounded-xl border border-line bg-white px-4 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/15" id="free-session-goal" name="session_goal" value="{{ old('session_goal') }}" placeholder="{{ __('public.field_goal_placeholder') }}" required maxlength="500">
                            </div>
                            <div class="grid gap-2">
                                <label class="text-sm font-semibold text-ink" for="free-session-notes">{{ __('public.field_notes') }}</label>
                                <textarea class="min-h-28 rounded-xl border border-line bg-white px-4 py-3 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/15" id="free-session-notes" name="session_notes" maxlength="5000" placeholder="{{ __('public.field_notes_placeholder') }}">{{ old('session_notes') }}</textarea>
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="grid gap-2">
                                    <label class="text-sm font-semibold text-ink" for="free-session-password">{{ __('public.field_password') }}</label>
                                    <input class="min-h-12 rounded-xl border border-line bg-white px-4 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/15" id="free-session-password" name="password" type="password" autocomplete="new-password" required>
                                </div>
                                <div class="grid gap-2">
                                    <label class="text-sm font-semibold text-ink" for="free-session-password-confirmation">{{ __('public.field_password_confirmation') }}</label>
                                    <input class="min-h-12 rounded-xl border border-line bg-white px-4 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/15" id="free-session-password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
                                </div>
                            </div>
                            <div class="rounded-xl bg-surface-subtle p-4 text-xs leading-relaxed text-muted">
                                {{ __('public.policy_summary') }} <a class="font-semibold text-primary underline" href="{{ route('policy','terms') }}">{{ __('public.footer_terms') }}</a> · <a class="font-semibold text-primary underline" href="{{ route('policy','privacy') }}">{{ __('public.footer_privacy') }}</a>
                            </div>
                            <label class="flex items-start gap-3 text-sm leading-relaxed text-muted">
                                <input class="mt-1 size-4 shrink-0 accent-primary" type="checkbox" name="accepted_policy" value="1" required @checked(old('accepted_policy'))>
                                <span>{{ __('public.policy_accept') }}</span>
                            </label>
                            @if(config('services.contact_verification.mode', 'email_and_whatsapp') !== 'email_only')
                                <label class="flex items-start gap-3 text-sm leading-relaxed text-muted">
                                    <input class="mt-1 size-4 shrink-0 accent-primary" type="checkbox" name="whatsapp_service_consent" value="1" required @checked(old('whatsapp_service_consent'))>
                                    <span>{{ __('public.whatsapp_service_consent') }}</span>
                                </label>
                            @endif
                            <input type="hidden" name="starts_at" value="{{ request('slot', old('starts_at', '')) }}" data-booking-starts-at required>
                            <button class="inline-flex min-h-12 w-full items-center justify-center rounded-full bg-primary px-6 py-3 text-sm font-bold text-white transition hover:bg-primary-hover" type="submit">{{ __('public.booking_create_account_continue') }}</button>
                        </form>
                        <form method="post" action="{{ route('booking.login') }}" data-booking-endpoint="{{ route('booking.login') }}" class="hidden space-y-5" data-booking-login-branch data-booking-async-form data-booking-auth-form>
                            @csrf
                            <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" data-booking-form-errors role="alert" aria-live="assertive" hidden></div>
                            <p class="type-card-copy mt-0">{{ __('public.booking_sign_in_text') }}</p>
                            <div class="grid gap-2">
                                <label class="text-sm font-semibold text-ink" for="free-session-login-email">{{ __('public.field_email') }}</label>
                                <input class="min-h-12 rounded-xl border border-line bg-white px-4 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/15" id="free-session-login-email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
                            </div>
                            <div class="grid gap-2">
                                <label class="text-sm font-semibold text-ink" for="free-session-login-password">{{ __('public.field_password') }}</label>
                                <input class="min-h-12 rounded-xl border border-line bg-white px-4 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/15" id="free-session-login-password" name="password" type="password" autocomplete="current-password" required>
                            </div>
                            <input type="hidden" name="starts_at" value="{{ request('slot', old('starts_at', '')) }}" data-booking-starts-at required>
                            <button class="inline-flex min-h-12 w-full items-center justify-center rounded-full bg-primary px-6 py-3 text-sm font-bold text-white transition hover:bg-primary-hover" type="submit">{{ __('public.booking_sign_in') }}</button>
                        </form>
                    @else
                        <p class="m-0 rounded-xl bg-surface-subtle p-4 text-sm text-muted">{{ __('public.booking_internal_disabled') }}</p>
                    @endif
                    </div>
                </div>
                @endif
            </div>
        </section>

    <script>
        (() => {
            const root = document.querySelector('[data-availability]');
            if (!root) return;

            const grid = root.querySelector('[data-weekgrid]');
            const mobileGrid = root.querySelector('[data-weekgrid-mobile]');
            const status = root.querySelector('[data-week-status]');
            let week = root.dataset.week;
            let navigationDirection = 0;
            let refreshTimer = null;
            let requestVersion = 0;
            let hasRenderedInitialWeek = false;
            const previous = root.querySelector('[data-prev]');
            const next = root.querySelector('[data-next]');
            const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Europe/Bucharest' });
            const emptyMessage = @json(__('public.availability_empty'));
            const lowMessage = @json(__('public.availability_low'));
            const countMessage = @json(__('public.availability_week_count'));
            const noTimesLabel = @json(__('public.slot_none'));
            const dayCountMessage = @json(__('public.availability_day_count'));
            const moreTimesMessage = @json(__('public.availability_more_times'));
            const fewerTimesMessage = @json(__('public.availability_fewer_times'));
            const loadError = @json(__('public.availability_error'));
            const internalDisabledMessage = @json(__('public.booking_internal_disabled'));
            const pastUnavailableMessage = @json(__('public.slot_unavailable_past'));
            const noticeUnavailableMessage = @json(__('public.slot_unavailable_notice'));
            const firstAvailableDayMessage = @json(__('public.first_available_day'));
            const minimumNoticeMilliseconds = 24 * 60 * 60 * 1000;
            const formatSlotDate = date => new Intl.DateTimeFormat('ro-RO', {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
            }).format(new Date(date + 'T12:00:00'));
            const monday = date => {
                const value = new Date(date + 'T12:00:00');
                value.setDate(value.getDate() - ((value.getDay() + 6) % 7));
                return value.toLocaleDateString('en-CA');
            };
            const add = (date, days) => {
                const value = new Date(date + 'T12:00:00');
                value.setDate(value.getDate() + days);
                return value.toLocaleDateString('en-CA');
            };
            const selectSlot = (day, slot, button) => {
                if (root.dataset.selectionMode === 'navigate') {
                    const base = root.dataset.bookUrl;
                    const join = base.includes('?') ? '&' : '?';
                    window.location.href = base + join + 'slot=' + encodeURIComponent(slot.starts_at);

                    return;
                }

                document.dispatchEvent(new CustomEvent('easyenglish:availability-slot-selected', {
                    detail: { startsAt: slot.starts_at, label: formatSlotDate(day.date) + ' · ' + slot.time, trigger: button },
                }));
            };

            const createSlotButton = (day, slot, compact = false) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = compact
                    ? 'min-h-10 rounded-[7px] border-0 bg-primary-soft px-0 py-2 text-[10px] leading-none font-semibold text-primary-strong transition hover:bg-primary hover:text-white focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus sm:text-xs'
                    : 'min-h-10 rounded-[7px] border-0 bg-primary-soft px-2 py-2 text-xs leading-none font-semibold text-primary-strong transition hover:bg-primary hover:text-white focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus';
                button.textContent = slot.time;

                const unavailableMessage = root.dataset.bookingEnabled !== 'true'
                    ? internalDisabledMessage
                    : slot.unavailable_reason === 'past'
                        ? pastUnavailableMessage
                        : noticeUnavailableMessage;
                const disabled = root.dataset.bookingEnabled !== 'true' || slot.bookable !== true;

                button.setAttribute('aria-label', day.date + ' ' + slot.time + (disabled ? ', ' + unavailableMessage : ''));
                button.disabled = disabled;
                button.setAttribute('aria-disabled', String(disabled));

                if (disabled) {
                    button.title = unavailableMessage;
                    button.classList.add('cursor-not-allowed', 'bg-surface-subtle', 'text-muted', 'opacity-70');
                    button.classList.remove('hover:bg-primary', 'hover:text-white');
                } else {
                    button.addEventListener('click', () => selectSlot(day, slot, button));
                }

                return button;
            };

            const renderMobileDay = (day, firstAvailableDate) => {
                const card = document.createElement('article');
                card.dataset.calendarDate = day.date;
                card.setAttribute('aria-label', day.date + (day.date === firstAvailableDate ? ', ' + firstAvailableDayMessage : ''));
                card.className = 'rounded-[13px] border border-primary-border bg-white p-3';
                if (day.date === firstAvailableDate) card.classList.add('border-primary', 'ring-2', 'ring-primary/15');

                const heading = document.createElement('div');
                heading.className = 'mb-2.5 flex items-center justify-between gap-3';

                const title = document.createElement('h4');
                title.className = 'm-0 text-sm font-bold text-ink';
                title.textContent = day.label + ', ' + day.day;

                const count = document.createElement('span');
                count.className = 'shrink-0 text-[11px] font-medium text-muted';
                const availableCount = day.slots.filter(slot => slot.bookable === true).length;
                count.textContent = availableCount
                    ? dayCountMessage.replace(':count', String(availableCount))
                    : noTimesLabel;
                heading.append(title, count);
                card.append(heading);

                if (!day.slots.length) {
                    card.classList.add('bg-surface-subtle');
                    mobileGrid.append(card);
                    return;
                }

                const slots = document.createElement('div');
                slots.className = 'grid grid-cols-4 gap-1.5';
                const visibleSlots = day.slots.slice(0, 4);
                visibleSlots.forEach(slot => slots.append(createSlotButton(day, slot)));
                card.append(slots);

                if (day.slots.length > 4) {
                    let expanded = false;
                    const toggle = document.createElement('button');
                    toggle.type = 'button';
                    toggle.className = 'mt-2 inline-flex min-h-9 w-full items-center justify-center rounded-lg bg-primary-soft px-3 py-2 text-xs font-bold text-primary-strong transition hover:bg-primary hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus';
                    toggle.textContent = moreTimesMessage.replace(':count', String(day.slots.length - 4));
                    toggle.setAttribute('aria-expanded', 'false');
                    toggle.addEventListener('click', () => {
                        expanded = !expanded;
                        slots.innerHTML = '';
                        (expanded ? day.slots : visibleSlots).forEach(slot => slots.append(createSlotButton(day, slot)));
                        toggle.textContent = expanded
                            ? fewerTimesMessage
                            : moreTimesMessage.replace(':count', String(day.slots.length - 4));
                        toggle.setAttribute('aria-expanded', String(expanded));
                    });
                    card.append(toggle);
                }

                mobileGrid.append(card);
            };

            const render = days => {
                grid.innerHTML = '';
                mobileGrid.innerHTML = '';
                const firstAvailableDay = days.find(day => day.slots.some(slot => slot.bookable === true));
                const firstAvailableDate = firstAvailableDay?.date || null;
                const availableSlotCount = days.reduce((total, day) => total + day.slots.filter(slot => slot.bookable === true).length, 0);

                days.forEach(day => {
                    renderMobileDay(day, firstAvailableDate);
                    const column = document.createElement('div');
                    column.className = 'min-w-0 rounded-[10px] border border-primary-border bg-surface p-1.5 sm:rounded-[13px] sm:p-3';
                    column.setAttribute('aria-label', day.date + (day.date === firstAvailableDate ? ', ' + firstAvailableDayMessage : ''));
                    if (day.date === firstAvailableDate) column.classList.add('border-primary', 'ring-2', 'ring-primary/15');

                    const heading = document.createElement('div');
                    heading.className = 'mb-2 flex min-h-9 flex-col items-center justify-center gap-0.5 text-[10px] font-bold text-ink sm:mb-3 sm:min-h-0 sm:flex-row sm:justify-between sm:gap-1 sm:text-xs';

                    const label = document.createElement('span');
                    label.textContent = day.label;
                    const number = document.createElement('span');
                    number.textContent = day.day;
                    number.className = 'text-muted';
                    heading.append(label, number);

                    const slots = document.createElement('div');
                    slots.className = 'grid gap-1 sm:gap-1.5';

                    if (!day.slots.length) {
                        const empty = document.createElement('span');
                        empty.className = 'flex min-h-10 items-center justify-center text-[11px] text-muted';
                        empty.textContent = '—';
                        empty.setAttribute('aria-label', noTimesLabel);
                        slots.append(empty);
                    } else {
                        day.slots.forEach(slot => slots.append(createSlotButton(day, slot, true)));
                    }

                    column.append(heading, slots);
                    grid.append(column);
                });

                status.textContent = availableSlotCount === 0
                    ? emptyMessage
                    : availableSlotCount <= 3
                        ? lowMessage
                        : countMessage.replace(':count', String(availableSlotCount));
                previous.disabled = week <= monday(today);
                root.dataset.firstAvailableDate = firstAvailableDate || '';

                if (!hasRenderedInitialWeek && firstAvailableDate) {
                    const firstCard = mobileGrid.querySelector('[data-calendar-date="' + firstAvailableDate + '"]');
                    if (firstCard && firstCard.getClientRects().length && firstCard.getBoundingClientRect().top > window.innerHeight) {
                        firstCard.scrollIntoView({ behavior: 'instant', block: 'start' });
                    }
                }
                hasRenderedInitialWeek = true;

                document.dispatchEvent(new CustomEvent('easyenglish:availability-rendered', {
                    detail: { root, direction: navigationDirection },
                }));
                navigationDirection = 0;
            };

            const scheduleRefresh = data => {
                if (refreshTimer !== null) window.clearTimeout(refreshTimer);
                refreshTimer = null;

                const serverNow = Date.parse(data.server_now);
                if (!Number.isFinite(serverNow)) return;

                const nextCutoffDelay = data.days
                    .flatMap(day => day.slots)
                    .filter(slot => slot.bookable === true)
                    .map(slot => Date.parse(slot.starts_at) - serverNow - minimumNoticeMilliseconds)
                    .filter(delay => Number.isFinite(delay) && delay >= 0)
                    .sort((a, b) => a - b)[0];

                if (nextCutoffDelay !== undefined) {
                    refreshTimer = window.setTimeout(load, Math.min(nextCutoffDelay + 1000, 2147480000));
                }
            };

            const load = () => {
                const currentRequest = ++requestVersion;
                if (refreshTimer !== null) window.clearTimeout(refreshTimer);
                refreshTimer = null;
                document.dispatchEvent(new CustomEvent('easyenglish:availability-loading', {
                    detail: { root, direction: navigationDirection },
                }));
                status.textContent = @json(__('public.availability_loading'));
                grid.innerHTML = '<p class="col-span-7 text-xs text-muted">' + @json(__('public.availability_loading')) + '</p>';
                mobileGrid.innerHTML = '<p class="text-xs text-muted">' + @json(__('public.availability_loading')) + '</p>';
                fetch('{{ route('availability') }}?week=' + encodeURIComponent(week), {
                    cache: 'no-store',
                    headers: { Accept: 'application/json' },
                })
                    .then(response => {
                        if (!response.ok) throw new Error('Availability request failed');
                        return response.json();
                    })
                    .then(data => {
                        if (currentRequest !== requestVersion) return;
                        render(data.days);
                        scheduleRefresh(data);
                        const initialSlot = root.dataset.initialSlot;
                        if (initialSlot && root.dataset.selectionMode !== 'navigate') {
                            const matchingDay = data.days.find(day => day.slots.some(slot => slot.starts_at === initialSlot));
                            const matchingSlot = matchingDay?.slots.find(slot => slot.starts_at === initialSlot && slot.bookable === true);
                            if (matchingSlot) {
                                document.dispatchEvent(new CustomEvent('easyenglish:availability-slot-selected', {
                                    detail: { startsAt: matchingSlot.starts_at, label: formatSlotDate(matchingDay.date) + ' · ' + matchingSlot.time },
                                }));
                            }
                        }
                    })
                    .catch(() => {
                        if (currentRequest !== requestVersion) return;
                        status.textContent = loadError;
                        grid.innerHTML = '';
                        mobileGrid.innerHTML = '';
                    });
            };

            previous.addEventListener('click', () => {
                if (!previous.disabled) {
                    week = add(week, -7);
                    navigationDirection = -1;
                    load();
                }
            });
            next.addEventListener('click', () => {
                week = add(week, 7);
                navigationDirection = 1;
                load();
            });
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) load();
            });
            window.addEventListener('focus', load);
            load();
        })();
    </script>
@endif
