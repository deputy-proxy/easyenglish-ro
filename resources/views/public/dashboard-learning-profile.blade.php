<section class="space-y-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-xl font-semibold text-zinc-900 dark:text-white">{{ __('public.profile_learning_title') }}</h2>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ __('public.profile_learning_help') }}</p>
        </div>
        <a href="{{ route('profile.edit') }}" class="inline-flex w-fit items-center rounded-full bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover focus:outline-none focus:ring-2 focus:ring-emerald-600 focus:ring-offset-2">
            {{ __('public.profile_edit') }}
        </a>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <article class="dashboard-panel">
            <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('public.profile_current_level') }}</p>
            <div class="mt-3 flex flex-wrap items-center gap-3">
                @if ($learnerProfileLevel)
                    <p class="text-3xl font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $learnerProfileLevel }}</p>
                    <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $learnerProfileLevelSource === 'assessed' ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200' }}">
                        {{ __('public.profile_level_' . $learnerProfileLevelSource) }}
                    </span>
                @else
                    <p class="text-lg font-semibold text-zinc-500 dark:text-zinc-400">{{ __('public.profile_level_not_set') }}</p>
                @endif
            </div>

            @if ($latestAssessedProgressReport)
                <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-300">
                    {{ __('public.profile_level_assessed_on', ['date' => $latestAssessedProgressReport->shared_at->timezone('Europe/Bucharest')->format('d.m.Y')]) }}
                </p>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $latestAssessedProgressReport->title }}</p>
            @elseif ($learnerProfileLevelSource === 'self_reported')
                <p class="mt-3 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ __('public.profile_level_self_reported_help') }}</p>
            @else
                <p class="mt-3 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ __('public.profile_assessment_empty') }}</p>
            @endif

            @if ($latestAssessedProgressReport && auth()->user()->english_level)
                <div class="mt-4 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('public.profile_level_self_reported') }}</p>
                    <p class="mt-1 text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ auth()->user()->english_level }}</p>
                </div>
            @endif
        </article>

        <article class="dashboard-panel">
            <h3 class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('public.profile_learning_goal') }}</h3>
            @if (auth()->user()->session_goal)
                <p class="mt-3 whitespace-pre-wrap text-base leading-7 text-zinc-900 dark:text-white">{{ auth()->user()->session_goal }}</p>
            @else
                <p class="mt-3 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ __('public.profile_no_goal') }}</p>
            @endif
        </article>
    </div>

    <article class="dashboard-panel">
        <h3 class="text-base font-semibold text-zinc-900 dark:text-white">{{ __('public.profile_learning_preferences') }}</h3>
        @if (auth()->user()->session_notes)
            <p class="mt-3 whitespace-pre-wrap text-sm leading-7 text-zinc-700 dark:text-zinc-200">{{ auth()->user()->session_notes }}</p>
        @else
            <p class="mt-3 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ __('public.profile_no_preferences') }}</p>
        @endif
    </article>

    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-zinc-50 p-4 dark:bg-zinc-800/70">
        <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('public.profile_assessment_label') }}</p>
        <a href="{{ route('dashboard', ['view' => 'progress']) }}" wire:navigate class="text-sm font-semibold text-primary-strong hover:underline dark:text-blue-300">
            {{ __('public.profile_view_progress') }} →
        </a>
    </div>
</section>
