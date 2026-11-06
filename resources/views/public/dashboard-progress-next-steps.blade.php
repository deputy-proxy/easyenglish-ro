<section class="dashboard-panel">
    <div>
        <h3 class="text-base font-semibold text-zinc-900 dark:text-white">{{ __('public.dashboard_progress_next_steps') }}</h3>
        <p class="mt-1 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_progress_next_steps_help') }}</p>
    </div>

    @if ($latestProgressReport && filled($latestProgressReport->recommendations))
        <div class="mt-4 rounded-xl bg-emerald-50 p-4 dark:bg-emerald-950/40">
            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-800 dark:text-emerald-200">{{ __('public.dashboard_progress_report_recommendations') }}</p>
            <p class="mt-2 whitespace-pre-wrap text-sm leading-7 text-emerald-950 dark:text-emerald-50">{{ $latestProgressReport->recommendations }}</p>
            <a href="{{ route('dashboard', ['view' => 'progress', 'report' => $latestProgressReport->id]) }}" wire:navigate class="mt-3 inline-flex text-sm font-semibold text-primary-strong underline dark:text-blue-300">
                {{ __('public.dashboard_progress_open_source_report') }}
            </a>
        </div>
    @elseif ($nextStepFeedback->isNotEmpty())
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            @foreach ($nextStepFeedback as $item)
                <article class="rounded-xl bg-zinc-50 p-4 dark:bg-zinc-800/70">
                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        {{ $item->metric_key === 'overall' ? __('public.dashboard_progress_overall_feedback') : __('public.progress_metric_' . $item->metric_key) }}
                    </p>
                    <p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-zinc-800 dark:text-zinc-100">{{ $item->feedback }}</p>
                    <p class="mt-3 text-xs text-zinc-500">
                        {{ $item->booking->starts_at->timezone('Europe/Bucharest')->format('d.m.Y') }}
                        @if ($item->recorder)
                            · {{ __('public.dashboard_progress_written_by', ['name' => $item->recorder->name]) }}
                        @endif
                    </p>
                </article>
            @endforeach
        </div>
    @else
        <p class="mt-4 rounded-xl border border-dashed border-zinc-300 p-4 text-sm leading-6 text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">
            {{ __('public.dashboard_progress_next_steps_empty') }}
        </p>
    @endif
</section>
