<section class="grid gap-4 lg:grid-cols-3">
    <article class="dashboard-panel">
        <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('public.dashboard_next_session') }}</p>
        @if ($nextSession)
            <p class="mt-3 text-xl font-semibold text-zinc-900 dark:text-white">{{ $nextSession->starts_at->timezone('Europe/Bucharest')->translatedFormat('D, j M Y') }}</p>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ $nextSession->starts_at->timezone('Europe/Bucharest')->format('H:i') }} · {{ $nextSession->duration_minutes }} {{ __('public.booking_duration_minutes') }}</p>
            <p class="mt-2 text-sm text-zinc-700 dark:text-zinc-200">
                {{ $nextSession->teacher?->name ?? __('public.dashboard_teacher_to_be_assigned') }}
            </p>
            <span class="mt-3 inline-flex rounded-full bg-zinc-100 px-3 py-1 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                {{ __('public.booking_status_'.$nextSession->status) }}
            </span>
            <a href="{{ $nextSessionUrl }}" class="mt-4 inline-flex text-sm font-semibold text-primary-strong underline dark:text-blue-300">
                {{ __('public.dashboard_next_session_details') }}
            </a>
            @include('public.dashboard-call-link', ['booking' => $nextSession])
        @else
            <p class="mt-3 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_no_next_session') }}</p>
            <a href="{{ route('dashboard', ['view' => 'calendar']) }}" wire:navigate class="mt-4 inline-flex text-sm font-semibold text-primary-strong underline dark:text-blue-300">
                {{ __('public.dashboard_book_session') }}
            </a>
        @endif
        <a href="{{ route('dashboard', ['view' => 'sessions']) }}" wire:navigate class="mt-3 block text-sm font-semibold text-zinc-600 underline dark:text-zinc-300">
            {{ __('public.dashboard_sessions') }}
        </a>
    </article>

    <article class="dashboard-panel">
        <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('public.dashboard_remaining_credits') }}</p>
        <p class="mt-3 text-4xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $remainingCredits }}</p>
        @if ($activeBundleCount > 0)
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ trans_choice('public.dashboard_active_bundle_count', $activeBundleCount, ['count' => $activeBundleCount]) }}
            </p>
        @else
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('public.dashboard_no_bundles') }}</p>
        @endif

        @if ($usableEntitlements->isNotEmpty())
            <div class="mt-4 space-y-3 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                @foreach ($usableEntitlements->take(2) as $entitlement)
                    <div class="flex items-start justify-between gap-3">
                        <p class="min-w-0 text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ $entitlement->bundle_name_snapshot ?: __('public.dashboard_purchase_bundle_name_unavailable') }}</p>
                        <p class="shrink-0 text-xs text-zinc-500">{{ $entitlement->credits_remaining + $entitlement->free_credits_remaining }} {{ __('public.dashboard_credit_short') }}</p>
                    </div>
                @endforeach
                @if ($activeBundleCount > 2)
                    <p class="text-xs text-zinc-500">{{ trans_choice('public.dashboard_more_bundles', $activeBundleCount - 2, ['count' => $activeBundleCount - 2]) }}</p>
                @endif
            </div>
            <div class="mt-4 rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-800/70">
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('public.dashboard_nearest_expiry') }}</p>
                @if ($nearestExpiringEntitlement)
                    <p class="mt-1 text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ $nearestExpiringEntitlement->bundle_name_snapshot ?: __('public.dashboard_purchase_bundle_name_unavailable') }} · {{ $nearestExpiringEntitlement->expires_at->timezone('Europe/Bucharest')->format('d.m.Y') }}</p>
                @else
                    <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">{{ __('public.dashboard_no_bundle_expiry') }}</p>
                @endif
            </div>
        @else
            <a href="{{ route('home') }}#bundles" class="mt-4 inline-flex text-sm font-semibold text-primary-strong underline dark:text-blue-300">{{ __('public.dashboard_buy_bundle') }}</a>
        @endif
        <a href="{{ route('dashboard', ['view' => 'purchases']) }}" wire:navigate class="mt-4 inline-flex text-sm font-semibold text-primary-strong underline dark:text-blue-300">
            {{ __('public.dashboard_purchases') }}
        </a>
    </article>

    <article class="dashboard-panel">
        <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('public.profile_current_level') }}</p>
        @if ($learnerProfileLevel)
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <p class="text-4xl font-semibold text-zinc-900 dark:text-white">{{ $learnerProfileLevel }}</p>
                <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $learnerProfileLevelSource === 'assessed' ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200' }}">
                    {{ __('public.profile_level_'.$learnerProfileLevelSource) }}
                </span>
            </div>
        @else
            <p class="mt-3 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_no_current_level') }}</p>
        @endif
        <a href="{{ route('dashboard', ['view' => 'profile']) }}" wire:navigate class="mt-4 inline-flex text-sm font-semibold text-primary-strong underline dark:text-blue-300">
            {{ __('public.dashboard_learning') }}
        </a>
    </article>
</section>

@if ($bookings->count() > 1)
    <section class="dashboard-panel">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('public.dashboard_upcoming') }}</h2>
        <div class="mt-3 divide-y divide-zinc-100 dark:divide-zinc-800">
            @foreach ($bookings->skip(1)->take(4) as $booking)
                <article class="py-4 first:pt-0 last:pb-0">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-semibold text-zinc-900 dark:text-white">{{ $booking->starts_at->timezone('Europe/Bucharest')->translatedFormat('D, j M Y · H:i') }}</p>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ $booking->duration_minutes }} {{ __('public.booking_duration_minutes') }} · {{ $booking->teacher?->name ?? __('public.dashboard_teacher_to_be_assigned') }}</p>
                        </div>
                        <span class="rounded-full bg-zinc-100 px-3 py-1 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">{{ __('public.booking_status_'.$booking->status) }}</span>
                    </div>
                    @include('public.dashboard-call-link', ['booking' => $booking])
                </article>
            @endforeach
        </div>
        <a href="{{ route('dashboard', ['view' => 'sessions']) }}" wire:navigate class="mt-3 inline-flex text-sm font-semibold text-primary-strong underline dark:text-blue-300">{{ __('public.dashboard_sessions') }}</a>
    </section>
@endif

@if ($progressHighlight)
    <section class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 dark:border-emerald-900 dark:bg-emerald-950/40">
        <p class="text-xs font-bold uppercase tracking-wide text-emerald-800 dark:text-emerald-200">{{ __('public.dashboard_progress_highlight_title') }}</p>
        <p class="mt-2 text-lg font-semibold text-emerald-950 dark:text-emerald-50">
            {{ $progressHighlight['metric'] }} · +{{ $progressHighlight['points'] }} {{ __('public.dashboard_progress_points') }}
        </p>
        <p class="mt-1 text-sm text-emerald-900/80 dark:text-emerald-100/80">{{ __('public.dashboard_progress_highlight_date', ['date' => $progressHighlight['date']]) }}</p>
        <a href="{{ route('dashboard', ['view' => 'progress']) }}" wire:navigate class="mt-3 inline-flex text-sm font-semibold text-emerald-900 underline dark:text-emerald-100">
            {{ __('public.profile_view_progress') }}
        </a>
    </section>
@endif

<section class="dashboard-panel">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('public.dashboard_quick_actions') }}</h2>
    </div>
    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <a href="{{ route('dashboard', ['view' => 'calendar']) }}" wire:navigate class="rounded-xl bg-primary px-4 py-3 text-center text-sm font-semibold text-white transition hover:bg-primary-hover focus:outline-none focus:ring-2 focus:ring-emerald-600 focus:ring-offset-2">
            {{ __('public.dashboard_book_session') }}
        </a>
        <a href="{{ route('dashboard', ['view' => 'sessions']) }}" wire:navigate class="rounded-xl border border-zinc-200 px-4 py-3 text-center text-sm font-semibold text-zinc-800 hover:bg-zinc-50 focus:outline-none focus:ring-2 focus:ring-emerald-600 focus:ring-offset-2 dark:border-zinc-700 dark:text-zinc-100 dark:hover:bg-zinc-800">
            {{ __('public.dashboard_sessions') }}
        </a>
        <a href="{{ route('dashboard', ['view' => 'progress']) }}" wire:navigate class="rounded-xl border border-zinc-200 px-4 py-3 text-center text-sm font-semibold text-zinc-800 hover:bg-zinc-50 focus:outline-none focus:ring-2 focus:ring-emerald-600 focus:ring-offset-2 dark:border-zinc-700 dark:text-zinc-100 dark:hover:bg-zinc-800">
            {{ __('public.dashboard_progress') }}
        </a>
        <a href="{{ route('dashboard', ['view' => 'purchases']) }}" wire:navigate class="rounded-xl border border-zinc-200 px-4 py-3 text-center text-sm font-semibold text-zinc-800 hover:bg-zinc-50 focus:outline-none focus:ring-2 focus:ring-emerald-600 focus:ring-offset-2 dark:border-zinc-700 dark:text-zinc-100 dark:hover:bg-zinc-800">
            {{ __('public.dashboard_purchases') }}
        </a>
    </div>
</section>

<section class="dashboard-panel">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('public.dashboard_recent_activity') }}</h2>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('public.dashboard_recent_activity_help') }}</p>
        </div>
    </div>

    @if ($recentActivity->isNotEmpty())
        <ol class="mt-4 divide-y divide-zinc-100 dark:divide-zinc-800">
            @foreach ($recentActivity as $activity)
                <li class="flex flex-col gap-2 py-4 first:pt-0 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ __('public.dashboard_activity_type_'.$activity['type']) }}</span>
                            <span class="text-xs text-zinc-500">{{ $activity['occurred_at']->timezone('Europe/Bucharest')->translatedFormat('D, j M Y · H:i') }}</span>
                        </div>
                        <p class="mt-2 font-semibold text-zinc-900 dark:text-white">{{ $activity['title'] }}</p>
                        <p class="mt-1 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ $activity['description'] }}</p>
                    </div>
                    <a href="{{ $activity['url'] }}" wire:navigate class="shrink-0 text-sm font-semibold text-primary-strong underline dark:text-blue-300">
                        {{ __('public.dashboard_activity_open') }}
                    </a>
                </li>
            @endforeach
        </ol>
    @else
        <p class="mt-4 rounded-xl border border-dashed border-zinc-300 p-4 text-sm leading-6 text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">
            {{ __('public.dashboard_activity_empty') }}
        </p>
    @endif
</section>
