<section class="space-y-4">
    <div>
        <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('public.dashboard_progress_assessment_history') }}</h3>
        <p class="mt-1 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_progress_assessment_history_help') }}</p>
        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ __('public.dashboard_progress_score_semantics') }}</p>
    </div>

    @if ($assessmentHistory && $assessmentHistory->count() > 0)
        <div class="space-y-4">
            @foreach ($assessmentHistory as $assessmentBooking)
                @php($assessment = $assessmentSummaries[$assessmentBooking->id])
                <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h4 class="font-semibold text-zinc-900 dark:text-white">{{ __('public.dashboard_session_number', ['number' => $assessmentBooking->id]) }}</h4>
                            <p class="mt-1 text-sm text-zinc-500">{{ $assessmentBooking->starts_at->timezone('Europe/Bucharest')->format('d.m.Y') }} · {{ $assessmentBooking->duration_minutes }} {{ __('public.dashboard_minutes') }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $assessment['complete'] ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' : 'bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-200' }}">
                                {{ $assessment['complete'] ? __('public.dashboard_progress_complete') : __('public.dashboard_progress_incomplete') }}
                            </span>
                            <span class="text-xs text-zinc-500">{{ __('public.dashboard_progress_scored_dimensions', ['count' => $assessment['score_count']]) }}</span>
                        </div>
                    </div>

                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full min-w-[34rem] text-left text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 text-xs text-zinc-500 dark:border-zinc-700">
                                    <th scope="col" class="py-2 pr-3 font-semibold">{{ __('public.dashboard_progress_dimension') }}</th>
                                    <th scope="col" class="px-3 py-2 font-semibold">{{ __('public.dashboard_progress_baseline') }}</th>
                                    <th scope="col" class="px-3 py-2 font-semibold">{{ __('public.dashboard_progress_current') }}</th>
                                    <th scope="col" class="px-3 py-2 font-semibold">{{ __('public.dashboard_potential') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($assessment['metrics'] as $metric => $scores)
                                    <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                                        <th scope="row" class="py-2 pr-3 font-medium text-zinc-800 dark:text-zinc-200">{{ __('public.progress_metric_' . $metric) }}</th>
                                        <td class="px-3 py-2 tabular-nums text-zinc-600 dark:text-zinc-300">{{ $scores['baseline'] ?? '—' }}</td>
                                        <td class="px-3 py-2 tabular-nums font-semibold text-zinc-900 dark:text-white">{{ $scores['current'] ?? '—' }}</td>
                                        <td class="px-3 py-2 tabular-nums text-zinc-600 dark:text-zinc-300">{{ $scores['potential'] ?? '—' }}</td>
                                    </tr>
                                    @if (filled($scores['feedback']))
                                        <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                                            <td colspan="4" class="pb-3 pl-3 text-xs leading-6 text-zinc-600 dark:text-zinc-400">{{ $scores['feedback'] }}</td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if (filled($assessment['overall_feedback']))
                        <div class="mt-4 rounded-xl bg-zinc-50 p-4 dark:bg-zinc-800/70">
                            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('public.dashboard_progress_overall_feedback') }}</p>
                            <p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-zinc-800 dark:text-zinc-100">{{ $assessment['overall_feedback'] }}</p>
                        </div>
                    @endif

                    <div class="mt-4 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                        <a href="{{ $assessment['session_url'] }}" class="text-sm font-semibold text-emerald-800 underline dark:text-emerald-300">
                            {{ __('public.dashboard_progress_related_session') }} · #{{ $assessmentBooking->id }}
                        </a>
                    </div>
                </article>
            @endforeach
        </div>
        <div>{{ $assessmentHistory->links() }}</div>
    @else
        <div class="rounded-2xl border border-dashed border-zinc-300 bg-white p-5 text-sm leading-6 text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
            {{ __('public.dashboard_progress_assessment_history_empty') }}
        </div>
    @endif
</section>
