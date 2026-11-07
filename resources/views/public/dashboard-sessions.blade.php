<section class="space-y-5">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 class="text-xl font-semibold text-zinc-900 dark:text-white">{{ __('public.dashboard_sessions') }}</h2>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_sessions_help') }}</p>
        </div>
        <a href="{{ route('dashboard', ['view' => 'calendar']) }}" wire:navigate class="inline-flex min-h-10 items-center rounded-full bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover">{{ __('public.dashboard_book_session') }}</a>
    </header>

    <nav class="flex flex-wrap gap-2" aria-label="{{ __('public.dashboard_sessions_filter') }}">
        @foreach (['upcoming' => __('public.dashboard_sessions_upcoming'), 'history' => __('public.dashboard_sessions_history'), 'cancelled' => __('public.dashboard_sessions_cancelled'), 'all' => __('public.dashboard_sessions_all')] as $group => $label)
            <a href="{{ route('dashboard', ['view' => 'sessions', 'session_group' => $group]) }}" wire:navigate @if ($sessionGroup === $group) aria-current="page" @endif class="rounded-full px-4 py-2 text-sm font-semibold transition {{ $sessionGroup === $group ? 'bg-primary text-white' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-700 dark:text-zinc-200' }}">{{ $label }}</a>
        @endforeach
    </nav>

    @if ($sessionBookings->isEmpty())
        <div class="rounded-2xl border border-dashed border-zinc-300 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="font-semibold text-zinc-900 dark:text-white">{{ __('public.dashboard_sessions_empty_title') }}</h3>
            <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_sessions_empty_text') }}</p>
            @if ($sessionGroup === 'upcoming')
                <a href="{{ route('dashboard', ['view' => 'calendar']) }}" wire:navigate class="mt-4 inline-flex min-h-10 items-center rounded-full bg-primary px-4 py-2 text-sm font-semibold text-white">{{ __('public.dashboard_book_session') }}</a>
            @endif
        </div>
    @else
        <div class="space-y-4">
            @foreach ($sessionBookings as $booking)
                @php
                    $localStart = $booking->starts_at->timezone('Europe/Bucharest');
                    $localEnd = $booking->ends_at->timezone('Europe/Bucharest');
                    $statusKey = 'public.booking_status_'.$booking->status;
                    $statusLabel = __($statusKey) !== $statusKey ? __($statusKey) : ucfirst(str_replace('_', ' ', $booking->status));
                    $actions = $sessionActions[$booking->id];
                @endphp
                <article class="dashboard-panel">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-400">{{ $localStart->translatedFormat('l, j F Y') }}</p>
                            <h3 class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">{{ $localStart->format('H:i') }}–{{ $localEnd->format('H:i') }}</h3>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ $booking->duration_minutes }} {{ __('public.booking_duration_minutes') }} · {{ __('public.dashboard_timezone_bucharest') }}</p>
                        </div>
                        <span class="w-fit rounded-full bg-zinc-100 px-3 py-1 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">{{ $statusLabel }}</span>
                    </div>

                    <p class="mt-4 text-sm font-medium text-zinc-900 dark:text-white">{{ $booking->session_goal }}</p>

                    @if ($booking->teacher)
                        <div class="mt-4 flex items-start gap-3">
                            @if ($booking->teacher->teacherProfile?->photo_path)
                                <img src="{{ Storage::disk('public')->url($booking->teacher->teacherProfile?->photo_path) }}" alt="" class="h-11 w-11 rounded-full object-cover">
                            @else
                                <div aria-hidden="true" class="flex h-11 w-11 items-center justify-center rounded-full bg-emerald-50 text-sm font-semibold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">{{ mb_substr($booking->teacher->name, 0, 1) }}</div>
                            @endif
                            <div>
                                <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('public.dashboard_session_teacher') }}: {{ $booking->teacher->name }}</p>
                                @if ($booking->teacher->teacherProfile?->bio)
                                    <p class="mt-1 text-sm leading-5 text-zinc-600 dark:text-zinc-300">{{ $booking->teacher->teacherProfile?->bio }}</p>
                                @endif
                            </div>
                        </div>
                    @else
                        <p class="mt-3 text-sm text-zinc-500">{{ __('public.dashboard_session_teacher_pending') }}</p>
                    @endif

                    @if ($booking->status === 'confirmed' && $booking->starts_at->isFuture())
                        @include('public.dashboard-call-link', ['booking' => $booking])
                    @endif

                    <details class="mt-4 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                        <summary class="cursor-pointer text-sm font-semibold text-primary-strong hover:underline dark:text-blue-300">{{ __('public.dashboard_session_details') }}</summary>
                        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                            <div><dt class="text-zinc-500">{{ __('public.booking_reference') }}</dt><dd class="mt-1 break-all font-medium text-zinc-900 dark:text-white">{{ $booking->public_reference }}</dd></div>
                            <div><dt class="text-zinc-500">{{ __('public.booking_status') }}</dt><dd class="mt-1 font-medium text-zinc-900 dark:text-white">{{ $statusLabel }}</dd></div>
                            <div class="sm:col-span-2"><dt class="text-zinc-500">{{ __('public.field_notes') }}</dt><dd class="mt-1 whitespace-pre-wrap text-zinc-700 dark:text-zinc-200">{{ $booking->notes ?: __('public.dashboard_session_no_notes') }}</dd></div>
                        </dl>
                    </details>

                    <div class="mt-5 flex flex-wrap gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                        <a href="{{ $actions['manage_url'] }}" class="inline-flex min-h-10 items-center rounded-full border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-800 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-100 dark:hover:bg-zinc-800">{{ $actions['can_cancel'] || $actions['can_reschedule'] ? __('public.dashboard_session_manage') : __('public.dashboard_session_view') }}</a>
                        @if ($actions['can_reschedule'] && is_string($actions['reschedule_url']))
                            <a href="{{ $actions['reschedule_url'] }}" class="inline-flex min-h-10 items-center rounded-full border border-primary px-4 py-2 text-sm font-semibold text-primary-strong hover:bg-primary-soft dark:border-blue-500 dark:text-blue-300 dark:hover:bg-blue-950">{{ __('public.booking_reschedule_title') }}</a>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>

        <div class="pt-2">{{ $sessionBookings->links() }}</div>
    @endif
</section>