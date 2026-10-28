<x-layouts::app :title="__('public.dashboard_overview')">
    <div class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-8">
        <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">Easy English</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-zinc-900 dark:text-white">{{ __('public.dashboard_welcome', ['name' => auth()->user()->name]) }}</h1>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_subtitle') }}</p>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('public.dashboard_logout_note') }}</p>
        </header>

        @if (session('status'))<p role="status" class="rounded-xl bg-emerald-50 p-4 text-sm font-medium text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">{{ session('status') }}</p>@endif

        <nav class="flex flex-wrap gap-2 border-b border-zinc-200 pb-3 dark:border-zinc-700" aria-label="{{ __('public.dashboard_overview') }}">
            @foreach (['overview' => __('public.dashboard_overview'), 'calendar' => __('public.dashboard_calendar'), 'progress' => __('public.dashboard_progress')] as $view => $label)
                <a href="{{ route('dashboard', ['view' => $view]) }}" wire:navigate class="rounded-full px-4 py-2 text-sm font-semibold transition {{ $activeView === $view ? 'bg-emerald-800 text-white' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-700 dark:text-zinc-200' }}">{{ $label }}</a>
            @endforeach
        </nav>

        @if ($activeView === 'overview')
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('public.dashboard_remaining_credits') }}</p>
                    <p class="mt-3 text-4xl font-semibold text-zinc-900 dark:text-white">{{ $remainingCredits }}</p>
                    <a href="{{ route('dashboard', ['view' => 'calendar']) }}" wire:navigate class="mt-4 inline-flex text-sm font-semibold text-emerald-800 hover:underline dark:text-emerald-400">{{ __('public.dashboard_book_session') }} →</a>
                </article>
                <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('public.dashboard_next_session') }}</p>
                    @if ($bookings->first())
                        <p class="mt-3 text-xl font-semibold text-zinc-900 dark:text-white">{{ $bookings->first()->starts_at->timezone('Europe/Bucharest')->translatedFormat('D, j M') }}</p>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ $bookings->first()->starts_at->timezone('Europe/Bucharest')->format('H:i') }} · 15 min</p>
                    @else
                        <p class="mt-3 text-lg font-semibold text-zinc-900 dark:text-white">{{ __('public.dashboard_no_next_session') }}</p>
                    @endif
                    <a href="{{ route('dashboard', ['view' => 'calendar']) }}" wire:navigate class="mt-4 inline-flex text-sm font-semibold text-emerald-800 hover:underline dark:text-emerald-400">{{ __('public.dashboard_calendar') }} →</a>
                </article>
                <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('public.dashboard_your_bundles') }}</p>
                    @forelse ($entitlements->where('status', 'active') as $entitlement)
                        <div class="mt-3 flex items-start justify-between gap-3">
                            <div><p class="font-semibold text-zinc-900 dark:text-white">{{ $entitlement->definition->name }}</p><p class="mt-1 text-sm text-zinc-500">{{ $entitlement->credits_remaining + $entitlement->free_credits_remaining }} {{ __('public.dashboard_remaining_credits') }}</p></div>
                            <span class="rounded-full bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-800">{{ $entitlement->expires_at?->timezone('Europe/Bucharest')->format('d.m.Y') }}</span>
                        </div>
                    @empty
                        <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_no_bundles') }}</p>
                    @endforelse
                    <a href="{{ route('home') }}#bundles" class="mt-4 inline-flex text-sm font-semibold text-emerald-800 hover:underline dark:text-emerald-400">{{ __('public.dashboard_buy_bundle') }} →</a>
                </article>
            </section>

            <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between gap-4"><h2 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('public.dashboard_upcoming') }}</h2><a href="{{ route('dashboard', ['view' => 'calendar']) }}" wire:navigate class="text-sm font-semibold text-emerald-800 hover:underline dark:text-emerald-400">{{ __('public.dashboard_calendar') }} →</a></div>
                @forelse ($bookings as $booking)
                    <div class="mt-4 flex flex-col gap-2 border-t border-zinc-100 pt-4 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800">
                        <div><p class="font-semibold text-zinc-900 dark:text-white">{{ $booking->starts_at->timezone('Europe/Bucharest')->translatedFormat('D, j M Y') }} · {{ $booking->starts_at->timezone('Europe/Bucharest')->format('H:i') }}</p><p class="mt-1 text-sm text-zinc-500">{{ $booking->session_goal }}</p></div>
                        <span class="w-fit rounded-full bg-zinc-100 px-3 py-1 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">{{ __( 'public.booking_status_'.$booking->status, [], app()->getLocale()) === 'public.booking_status_'.$booking->status ? ucfirst(str_replace('_', ' ', $booking->status)) : __('public.booking_status_'.$booking->status) }}</span>
                    </div>
                @empty
                    <p class="mt-4 text-sm text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_no_upcoming') }}</p>
                @endforelse
            </section>
        @elseif ($activeView === 'calendar')
            <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="text-xl font-semibold text-zinc-900 dark:text-white">{{ __('public.dashboard_calendar_title') }}</h2>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_calendar_help') }}</p>
                @if (str_contains($calendarBookingUrl, '/book'))
                    <div class="mt-5" data-availability data-week="{{ $calendarWeekStart->format('Y-m-d') }}" data-book-url="{{ $calendarBookingUrl }}">
                        <div class="mb-4 flex justify-between gap-3"><button type="button" data-prev class="rounded-full border px-3 py-2 text-sm">← {{ __('public.previous_week') }}</button><button type="button" data-next class="rounded-full border px-3 py-2 text-sm">{{ __('public.next_week') }} →</button></div>
                        <div data-weekgrid class="grid gap-3 sm:grid-cols-4 lg:grid-cols-7"><p>{{ __('public.availability_loading') }}</p></div>
                        <p class="mt-4 text-xs text-zinc-500">{{ __('public.timezone_note') }}</p>
                    </div>
                    <script>
                        (() => {
                            const root = document.querySelector('[data-availability]');
                            if (!root) return;
                            const grid = root.querySelector('[data-weekgrid]');
                            let week = root.dataset.week;
                            const base = root.dataset.bookUrl;
                            const previous = root.querySelector('[data-prev]');
                            const next = root.querySelector('[data-next]');
                            const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Europe/Bucharest' });
                            const add = (date, days) => { const d = new Date(date + 'T12:00:00'); d.setDate(d.getDate() + days); return d.toLocaleDateString('en-CA'); };
                            const monday = date => { const d = new Date(date + 'T12:00:00'); d.setDate(d.getDate() - ((d.getDay() + 6) % 7)); return d.toLocaleDateString('en-CA'); };
                            const render = days => {
                                grid.innerHTML = '';
                                days.forEach(day => {
                                    const col = document.createElement('div'); col.className = 'rounded-xl border border-zinc-200 p-3 dark:border-zinc-700';
                                    const head = document.createElement('p'); head.className = 'mb-3 font-semibold text-zinc-800 dark:text-zinc-100'; head.textContent = `${day.label} ${day.day}`; col.append(head);
                                    day.slots.forEach(slot => {
                                        const button = document.createElement('button'); button.type = 'button'; button.className = 'mb-2 w-full rounded-lg bg-emerald-50 px-2 py-2 text-sm font-semibold text-emerald-900 hover:bg-emerald-100'; button.textContent = slot.time;
                                        button.addEventListener('click', () => { const join = base.includes('?') ? '&' : '?'; location.href = base + join + 'slot=' + encodeURIComponent(slot.starts_at); }); col.append(button);
                                    });
                                    if (!day.slots.length) { const empty = document.createElement('p'); empty.className = 'text-xs text-zinc-500'; empty.textContent = @json(__('public.slot_none')); col.append(empty); }
                                    grid.append(col);
                                });
                            };
                            const load = () => { grid.innerHTML = '<p>' + @json(__('public.availability_loading')) + '</p>'; fetch('{{ route('availability') }}?week=' + encodeURIComponent(week), { headers: { Accept: 'application/json' } }).then(response => response.json()).then(data => render(data.days)).catch(() => { grid.textContent = @json(__('public.dashboard_calendar_full')); }); previous.disabled = week <= monday(today); };
                            previous.addEventListener('click', () => { if (!previous.disabled) { week = add(week, -7); load(); } });
                            next.addEventListener('click', () => { week = add(week, 7); load(); });
                            load();
                        })();
                    </script>
                @else
                    <div class="mt-5 rounded-xl bg-zinc-50 p-5 dark:bg-zinc-800"><p class="text-sm text-zinc-700 dark:text-zinc-200">{{ __('public.dashboard_no_credits') }}</p><a class="mt-4 inline-flex rounded-full bg-emerald-800 px-4 py-2 text-sm font-semibold text-white" href="{{ route('home') }}#bundles">{{ __('public.dashboard_buy_bundle') }}</a></div>
                @endif
                <h3 class="mt-8 text-lg font-semibold text-zinc-900 dark:text-white">{{ __('public.dashboard_upcoming') }}</h3>
                @forelse ($bookings as $booking)
                    <div class="mt-3 flex flex-wrap justify-between gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800"><span>{{ $booking->starts_at->timezone('Europe/Bucharest')->translatedFormat('D, j M Y') }} · {{ $booking->starts_at->timezone('Europe/Bucharest')->format('H:i') }}</span><span class="text-sm text-zinc-500">{{ $booking->session_goal }}</span></div>
                @empty
                    <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_no_upcoming') }}</p>
                @endforelse
            </section>
        @else
            <section>
                <h2 class="text-xl font-semibold text-zinc-900 dark:text-white">{{ __('public.dashboard_progress_title') }}</h2>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_progress_help') }}</p>
                @if ($feedback->isEmpty())
                    <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach (['vocabulary', 'pronunciation', 'fluency'] as $metric)
                            <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"><p class="text-xs font-bold uppercase tracking-widest text-emerald-700">{{ ucfirst($metric) }}</p><h3 class="mt-3 text-lg font-semibold text-zinc-900 dark:text-white">{{ $hasActiveBundle ? __('public.dashboard_current') : __('public.dashboard_potential') }}</h3><p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ $hasActiveBundle ? __('public.dashboard_feedback_empty_bundle') : __('public.dashboard_feedback_empty') }}</p></article>
                        @endforeach
                    </div>
                @else
                    <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($feedback as $item)
                            <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                                <p class="text-xs font-bold uppercase tracking-widest text-emerald-700">{{ ucfirst($item->metric_key) }}</p>
                                @if ($hasActiveBundle)
                                    <div class="mt-4 grid grid-cols-2 gap-3"><div><p class="text-xs text-zinc-500">{{ __('public.dashboard_baseline') }}</p><p class="mt-1 text-2xl font-semibold">{{ $item->baseline_score ?? '—' }}<span class="text-sm font-normal text-zinc-400">/100</span></p></div><div><p class="text-xs text-zinc-500">{{ __('public.dashboard_current') }}</p><p class="mt-1 text-2xl font-semibold">{{ $item->current_score ?? '—' }}<span class="text-sm font-normal text-zinc-400">/100</span></p></div></div>
                                @endif
                                <div class="mt-4 rounded-xl bg-emerald-50 p-3 dark:bg-emerald-950"><p class="text-xs font-semibold text-emerald-900 dark:text-emerald-100">{{ __('public.dashboard_potential') }}</p><p class="mt-1 text-2xl font-semibold text-emerald-900 dark:text-emerald-100">{{ $item->potential_score ?? '—' }}<span class="text-sm font-normal">/100</span></p></div>
                                @if ($item->feedback)<p class="mt-4 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ $item->feedback }}</p>@endif
                                <p class="mt-3 text-xs text-zinc-500">{{ $item->booking->starts_at->timezone('Europe/Bucharest')->format('d.m.Y') }}</p>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>
        @endif
    </div>
</x-layouts::app>
