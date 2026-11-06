<section class="space-y-4">
    <div>
        <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('public.dashboard_progress_reports_title') }}</h3>
        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_progress_reports_help') }}</p>
    </div>

    @if ($selectedProgressReport)
        <article class="rounded-2xl border border-emerald-200 bg-white p-5 shadow-sm dark:border-emerald-900 dark:bg-zinc-900">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">{{ __('public.dashboard_progress_report_detail') }}</p>
                    <h4 class="mt-1 text-xl font-semibold text-zinc-900 dark:text-white">{{ $selectedProgressReport->title }}</h4>
                    <p class="mt-2 text-sm text-zinc-500">
                        {{ __('public.dashboard_progress_report_date') }} · {{ $selectedProgressReport->shared_at->timezone('Europe/Bucharest')->format('d.m.Y') }}
                        @if ($selectedProgressReport->author)
                            · {{ __('public.dashboard_progress_written_by', ['name' => $selectedProgressReport->author->name]) }}
                        @endif
                    </p>
                </div>
                @if ($selectedProgressReport->overall_level)
                    <span class="rounded-full bg-emerald-50 px-3 py-1 text-sm font-semibold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">
                        {{ __('public.dashboard_progress_overall_level') }}: {{ $selectedProgressReport->overall_level }}
                    </span>
                @endif
            </div>

            <div class="mt-5 space-y-5">
                <div>
                    <h5 class="text-xs font-bold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('public.dashboard_progress_report_summary') }}</h5>
                    <p class="mt-2 whitespace-pre-wrap text-sm leading-7 text-zinc-800 dark:text-zinc-100">{{ $selectedProgressReport->summary }}</p>
                </div>

                @foreach ([
                    ['strengths', $selectedProgressReport->strengths],
                    ['areas_to_improve', $selectedProgressReport->areas_to_improve],
                    ['recommendations', $selectedProgressReport->recommendations],
                ] as [$key, $value])
                    @if (filled($value))
                        <div class="border-t border-zinc-100 pt-4 dark:border-zinc-800">
                            <h5 class="text-xs font-bold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('public.dashboard_progress_' . $key) }}</h5>
                            <p class="mt-2 whitespace-pre-wrap text-sm leading-7 text-zinc-800 dark:text-zinc-100">{{ $value }}</p>
                        </div>
                    @endif
                @endforeach
            </div>

            <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <a href="{{ $selectedProgressReportSessionUrl }}" class="text-sm font-semibold text-primary-strong underline dark:text-blue-300">
                    {{ __('public.dashboard_progress_related_session') }} · #{{ $selectedProgressReport->booking->id }}
                </a>
                <a href="{{ route('dashboard', ['view' => 'progress']) }}" wire:navigate class="text-sm font-semibold text-zinc-600 underline dark:text-zinc-300">
                    {{ __('public.dashboard_progress_back_to_reports') }}
                </a>
            </div>
        </article>
    @endif

    @if ($progressReports && $progressReports->count() > 0)
        <div class="grid gap-3 lg:grid-cols-2">
            @foreach ($progressReports as $report)
                <article class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <h4 class="font-semibold text-zinc-900 dark:text-white">{{ $report->title }}</h4>
                            <p class="mt-1 text-xs text-zinc-500">
                                {{ $report->shared_at->timezone('Europe/Bucharest')->format('d.m.Y') }}
                                @if ($report->author)
                                    · {{ __('public.dashboard_progress_written_by', ['name' => $report->author->name]) }}
                                @endif
                            </p>
                        </div>
                        @if ($report->overall_level)
                            <span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">{{ $report->overall_level }}</span>
                        @endif
                    </div>
                    <p class="mt-3 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ \Illuminate\Support\Str::limit($report->summary, 180) }}</p>
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                        <a href="{{ $progressReportSessionUrls[$report->id] }}" class="text-xs font-medium text-zinc-500 underline dark:text-zinc-400">
                            {{ __('public.dashboard_progress_related_session') }} · #{{ $report->booking->id }}
                        </a>
                        <a href="{{ route('dashboard', ['view' => 'progress', 'report' => $report->id]) }}" wire:navigate class="text-sm font-semibold text-primary-strong underline dark:text-blue-300">
                            {{ __('public.dashboard_progress_read_report') }}
                        </a>
                    </div>
                </article>
            @endforeach
        </div>
        <div>{{ $progressReports->links() }}</div>
    @else
        <div class="rounded-2xl border border-dashed border-zinc-300 bg-white p-5 text-sm leading-6 text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
            {{ __('public.dashboard_progress_reports_empty') }}
        </div>
    @endif
</section>
