<x-layouts::app :title="__('public.dashboard_overview')">
    <div class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-8">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">Easy English</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-zinc-900 dark:text-white">{{ __('public.dashboard_welcome', ['name' => auth()->user()->name]) }}</h1>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_subtitle') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                @if (auth()->user()->isTeacher())
                    <a href="{{ route('teacher.availability') }}" class="text-sm font-semibold text-zinc-600 underline dark:text-zinc-300">{{ __('public.dashboard_teacher_availability') }}</a>
                @endif
                @if ($supportChannelsAvailable)
                    <a href="#dashboard-support" class="text-sm font-semibold text-zinc-600 underline dark:text-zinc-300">{{ __('public.dashboard_support_title') }}</a>
                @endif
                @if ($activeView === 'calendar')
                    <a href="{{ route('dashboard', ['view' => 'overview']) }}" wire:navigate class="inline-flex items-center rounded-full border border-zinc-300 px-5 py-2.5 text-sm font-semibold text-zinc-800 hover:bg-zinc-50 focus:outline-none focus:ring-2 focus:ring-emerald-600 focus:ring-offset-2 dark:border-zinc-600 dark:text-zinc-100 dark:hover:bg-zinc-800">
                        {{ __('public.dashboard_back_to_overview') }}
                    </a>
                @else
                    <a href="{{ route('dashboard', ['view' => 'calendar']) }}" wire:navigate class="inline-flex items-center rounded-full bg-emerald-800 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-900 focus:outline-none focus:ring-2 focus:ring-emerald-600 focus:ring-offset-2">
                        {{ __('public.dashboard_book_session') }}
                    </a>
                @endif
            </div>
        </header>

        @if (session('status'))<p role="status" class="rounded-xl bg-emerald-50 p-4 text-sm font-medium text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">{{ session('status') }}</p>@endif

        @php($learningActive = in_array($activeView, ['profile', 'progress'], true))
        <nav class="flex flex-wrap items-center gap-2 border-b border-zinc-200 pb-3 dark:border-zinc-700" aria-label="{{ __('public.dashboard_primary_navigation') }}">
            @foreach ([
                'overview' => __('public.dashboard_overview'),
                'sessions' => __('public.dashboard_sessions'),
                'learning' => __('public.dashboard_learning'),
                'purchases' => __('public.dashboard_purchases'),
            ] as $view => $label)
                @php($isActive = $view === 'learning' ? $learningActive : $activeView === $view)
                <a href="{{ route('dashboard', ['view' => $view === 'learning' ? 'profile' : $view]) }}" wire:navigate @if ($isActive) aria-current="page" @endif class="rounded-full px-4 py-2 text-sm font-semibold transition {{ $isActive ? 'bg-emerald-800 text-white' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-700 dark:text-zinc-200' }}">{{ $label }}</a>
            @endforeach
            <a href="{{ route('dashboard', ['view' => 'account']) }}" wire:navigate @if ($activeView === 'account') aria-current="page" @endif class="rounded-full px-4 py-2 text-sm font-semibold transition {{ $activeView === 'account' ? 'bg-emerald-800 text-white' : 'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-700' }}">
                {{ __('public.dashboard_account') }}
            </a>
        </nav>

        @if ($learningActive)
            <nav class="flex flex-wrap gap-2" aria-label="{{ __('public.dashboard_learning_navigation') }}">
                <a href="{{ route('dashboard', ['view' => 'profile']) }}" wire:navigate @if ($activeView === 'profile') aria-current="page" @endif class="rounded-lg px-3 py-2 text-sm font-medium {{ $activeView === 'profile' ? 'bg-emerald-50 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' }}">{{ __('public.dashboard_learning_profile_tab') }}</a>
                <a href="{{ route('dashboard', ['view' => 'progress']) }}" wire:navigate @if ($activeView === 'progress') aria-current="page" @endif class="rounded-lg px-3 py-2 text-sm font-medium {{ $activeView === 'progress' ? 'bg-emerald-50 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' }}">{{ __('public.dashboard_learning_progress_tab') }}</a>
            </nav>
        @endif

        @if ($activeView === 'account')
            @include('public.dashboard-account')
        @elseif ($activeView === 'overview')
            @include('public.dashboard-overview')
        @elseif ($activeView === 'sessions')
            @include('public.dashboard-sessions')
        @elseif ($activeView === 'purchases')
            @include('public.dashboard-purchases')
        @elseif ($activeView === 'profile')
            @include('public.dashboard-learning-profile')
        @elseif ($activeView === 'calendar')
            <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="text-xl font-semibold text-zinc-900 dark:text-white">{{ __('public.dashboard_calendar_title') }}</h2>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_calendar_help') }}</p>
                @if (str_contains($calendarBookingUrl, '/book'))
                    <div class="mt-5" data-availability data-week="{{ $calendarWeekStart->format('Y-m-d') }}" data-book-url="{{ $calendarBookingUrl }}">
                        <div class="mb-4 flex items-center justify-between gap-3" data-week-navigation>
                            <button class="testimonial-arrow" type="button" data-prev aria-label="{{ __('public.previous_week') }}">
                                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5m7 7-7-7 7-7" /></svg>
                            </button>
                            <button class="testimonial-arrow testimonial-arrow--next" type="button" data-next aria-label="{{ __('public.next_week') }}">
                                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-7-7 7 7-7 7" /></svg>
                            </button>
                        </div>
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
                    <div class="mt-3 flex flex-wrap justify-between gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800"><div><span>{{ $booking->starts_at->timezone('Europe/Bucharest')->translatedFormat('D, j M Y') }} · {{ $booking->starts_at->timezone('Europe/Bucharest')->format('H:i') }}</span><p class="text-sm text-zinc-500">{{ $booking->session_goal }}</p>@include('public.dashboard-call-link', ['booking' => $booking])</div></div>
                @empty
                    <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_no_upcoming') }}</p>
                @endforelse
            </section>
        @else
            <section class="space-y-5">
                <div>
                    <h2 class="text-xl font-semibold text-zinc-900 dark:text-white">{{ __('public.dashboard_progress_title') }}</h2>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_progress_help') }}</p>
                </div>

                @include('public.dashboard-progress-next-steps')

                @if (count($progressChart['series']) > 0)
                    <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900" data-progress-chart>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="text-base font-semibold text-zinc-900 dark:text-white">{{ __('public.dashboard_progress_chart_title') }}</h3>
                                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('public.dashboard_progress_chart_help') }}</p>
                            </div>
                            <p class="text-xs text-zinc-500">{{ trans_choice('public.dashboard_progress_assessment_count', $progressChart['assessment_count'], ['count' => $progressChart['assessment_count']]) }}</p>
                        </div>

                        <div class="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1.4fr)_minmax(16rem,0.8fr)] lg:items-center">
                            <div class="min-w-0">
                                <svg viewBox="0 0 440 390" role="img" aria-label="{{ __('public.dashboard_progress_chart_aria') }}" class="mx-auto block w-full max-w-xl overflow-visible" data-radar-svg>
                                    <g data-radar-grid></g>
                                    <g data-radar-axes></g>
                                    <g data-radar-series></g>
                                    <g data-radar-labels></g>
                                </svg>
                                <div class="mt-3 flex flex-wrap justify-center gap-x-4 gap-y-2" data-radar-legend>
                                    @foreach ($progressChart['series'] as $series)
                                        <button type="button" data-series-id="{{ $series['id'] }}" aria-pressed="{{ $loop->last ? 'true' : 'false' }}" class="inline-flex items-center gap-2 text-xs font-medium text-zinc-600 hover:text-zinc-950 dark:text-zinc-300 dark:hover:text-white">
                                            <span class="h-2.5 w-2.5 rounded-full {{ $series['type'] === 'baseline' ? 'border border-dashed border-zinc-500 bg-transparent' : ($loop->last ? 'bg-emerald-700' : 'bg-teal-300') }}"></span>
                                            {{ $series['label'] }}@if ($series['date']) <span class="text-zinc-400">· {{ $series['date'] }}</span>@endif
                                        </button>
                                    @endforeach
                                </div>
                            </div>

                            <div class="rounded-xl bg-zinc-50 p-4 dark:bg-zinc-800/70" aria-live="polite" data-radar-details>
                                <p class="text-xs font-bold uppercase tracking-wider text-emerald-700" data-detail-label></p>
                                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400" data-detail-date></p>
                                <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3" data-detail-scores></dl>
                                <div class="mt-4 space-y-3" data-detail-feedback></div>
                            </div>
                        </div>

                        <details class="mt-4 rounded-xl border border-zinc-200 px-4 py-3 dark:border-zinc-700">
                            <summary class="cursor-pointer text-sm font-semibold text-zinc-700 dark:text-zinc-200">{{ __('public.dashboard_progress_data_table') }}</summary>
                            <div class="mt-3 overflow-x-auto">
                                <table class="w-full min-w-[34rem] text-left text-xs">
                                    <thead><tr class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700"><th class="py-2 pr-3">{{ __('public.dashboard_progress_series') }}</th>@foreach (['vocabulary', 'pronunciation', 'fluency', 'grammar', 'confidence'] as $metric)<th class="px-2 py-2">{{ __('public.progress_metric_' . $metric) }}</th>@endforeach</tr></thead>
                                    <tbody>
                                        @foreach ($progressChart['series'] as $series)
                                            <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800"><th class="py-2 pr-3 font-medium text-zinc-700 dark:text-zinc-200">{{ $series['label'] }} @if ($series['date']) · {{ $series['date'] }}@endif</th>@foreach (['vocabulary', 'pronunciation', 'fluency', 'grammar', 'confidence'] as $metric)<td class="px-2 py-2 tabular-nums text-zinc-600 dark:text-zinc-300">{{ $series['scores'][$metric] }}</td>@endforeach</tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </details>

                        @if ($progressChart['strongest_improvement'])
                            <div class="mt-4 flex flex-wrap items-center gap-2 rounded-xl bg-emerald-50 px-4 py-3 dark:bg-emerald-950/50">
                                <span class="text-xs font-semibold text-emerald-900 dark:text-emerald-100">{{ __('public.dashboard_progress_strongest') }}</span>
                                <strong class="text-sm text-emerald-950 dark:text-emerald-50">{{ __('public.progress_metric_' . $progressChart['strongest_improvement']['metric']) }}</strong>
                                <span class="text-sm font-bold text-emerald-800 dark:text-emerald-200">+{{ $progressChart['strongest_improvement']['points'] }} {{ __('public.dashboard_progress_points') }}</span>
                            </div>
                        @endif
                        @if ($progressChart['partial_count'] > 0)
                            <p class="mt-4 text-xs text-zinc-500 dark:text-zinc-400">{{ __('public.dashboard_progress_partial_note', ['count' => $progressChart['partial_count']]) }}</p>
                        @endif
                        <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">{{ __('public.dashboard_progress_note') }}</p>
                        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ __('public.dashboard_progress_context_note') }}</p>
                    </article>
                @else
                    <div class="rounded-2xl border border-dashed border-zinc-300 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                        <h3 class="font-semibold text-zinc-900 dark:text-white">{{ __('public.dashboard_progress_waiting_title') }}</h3>
                        <p class="mt-2 max-w-2xl text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_progress_waiting_text') }}</p>
                    </div>
                @endif

                @if (count($progressChart['series']) === 0 && $progressChart['partial_count'] > 0)
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('public.dashboard_progress_partial_note', ['count' => $progressChart['partial_count']]) }}</p>
                @endif

                @include('public.dashboard-progress-reports')

                @if ($feedback->isNotEmpty())
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($feedback as $item)
                            <article class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                                <p class="text-xs font-bold uppercase tracking-widest text-emerald-700">{{ __('public.progress_metric_' . $item->metric_key) }}</p>
                                @if ($item->current_score !== null)
                                    <p class="mt-2 text-2xl font-semibold text-zinc-900 dark:text-white">{{ $item->current_score }}<span class="text-sm font-normal text-zinc-400">/100</span></p>
                                @endif
                                @if ($item->potential_score !== null)
                                    <div class="mt-3 rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800">
                                        <p class="text-xs font-semibold text-zinc-500">{{ __('public.dashboard_potential') }}</p>
                                        <p class="mt-1 text-lg font-semibold text-zinc-700 dark:text-zinc-200">{{ $item->potential_score }}<span class="text-sm font-normal text-zinc-400">/100</span></p>
                                    </div>
                                @endif
                                @if ($item->feedback)
                                    <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ $item->feedback }}</p>
                                @endif
                                <p class="mt-3 text-xs text-zinc-500">{{ $item->booking->starts_at->timezone('Europe/Bucharest')->format('d.m.Y') }}</p>
                            </article>
                        @endforeach
                    </div>
                @endif

                @include('public.dashboard-assessment-history')
            </section>

            @if (count($progressChart['series']) > 0)
                <script>
                    (() => {
                        const root = document.querySelector('[data-progress-chart]');
                        if (!root || root.dataset.initialized) return;
                        root.dataset.initialized = 'true';

                        const series = @js($progressChart['series']);
                        const metrics = [
                            { key: 'vocabulary', label: @js(__('public.progress_metric_vocabulary')) },
                            { key: 'pronunciation', label: @js(__('public.progress_metric_pronunciation')) },
                            { key: 'fluency', label: @js(__('public.progress_metric_fluency')) },
                            { key: 'grammar', label: @js(__('public.progress_metric_grammar')) },
                            { key: 'confidence', label: @js(__('public.progress_metric_confidence')) },
                        ];
                        const svg = root.querySelector('[data-radar-svg]');
                        const grid = root.querySelector('[data-radar-grid]');
                        const axes = root.querySelector('[data-radar-axes]');
                        const shapes = root.querySelector('[data-radar-series]');
                        const labels = root.querySelector('[data-radar-labels]');
                        const cx = 220, cy = 184, radius = 124;
                        const point = (index, value) => {
                            const angle = (-Math.PI / 2) + (index * Math.PI * 2 / metrics.length);
                            const distance = radius * value / 100;
                            return [cx + Math.cos(angle) * distance, cy + Math.sin(angle) * distance];
                        };
                        const make = (name, attrs = {}) => {
                            const node = document.createElementNS('http://www.w3.org/2000/svg', name);
                            Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, String(value)));
                            return node;
                        };
                        const polygonPoints = scores => scores.map((score, index) => point(index, score).join(',')).join(' ');

                        [20, 40, 60, 80, 100].forEach(level => {
                            const points = metrics.map((_, index) => point(index, level).join(',')).join(' ');
                            grid.append(make('polygon', { points, fill: 'none', stroke: 'currentColor', 'stroke-opacity': level === 100 ? '.22' : '.12', 'stroke-width': 1 }));
                        });
                        metrics.forEach((metric, index) => {
                            const [x, y] = point(index, 100);
                            axes.append(make('line', { x1: cx, y1: cy, x2: x, y2: y, stroke: 'currentColor', 'stroke-opacity': '.18', 'stroke-width': 1 }));
                            const labelPoint = point(index, 122);
                            const text = make('text', { x: labelPoint[0], y: labelPoint[1], fill: 'currentColor', 'font-size': 11, 'text-anchor': labelPoint[0] < cx - 8 ? 'end' : (labelPoint[0] > cx + 8 ? 'start' : 'middle'), 'dominant-baseline': labelPoint[1] < cy - 8 ? 'auto' : (labelPoint[1] > cy + 8 ? 'hanging' : 'middle') });
                            text.textContent = metric.label;
                            labels.append(text);
                        });

                        let historicalIndex = 0;
                        const historicalColors = ['#99f6e4', '#5eead4', '#0f766e'];
                        const colors = series.map(item => item.type === 'baseline' ? '#71717a' : (item.id === series[series.length - 1].id ? '#047857' : historicalColors[Math.min(historicalIndex++, historicalColors.length - 1)]));
                        series.forEach((item, index) => {
                            const group = make('g', { 'data-series-shape': item.id });
                            const polygon = make('polygon', {
                                points: polygonPoints(metrics.map(metric => item.scores[metric.key])),
                                fill: colors[index], 'fill-opacity': item.type === 'baseline' ? 0 : (item.id === series[series.length - 1].id ? '.16' : '.07'),
                                stroke: colors[index], 'stroke-width': item.type === 'baseline' ? 2 : (item.id === series[series.length - 1].id ? 3 : 1.7),
                                'stroke-dasharray': item.type === 'baseline' ? '5 5' : 'none', 'stroke-linejoin': 'round'
                            });
                            group.append(polygon);
                            metrics.forEach((metric, metricIndex) => {
                                const [x, y] = point(metricIndex, item.scores[metric.key]);
                                group.append(make('circle', { cx: x, cy: y, r: item.id === series[series.length - 1].id ? 3.5 : 2.5, fill: colors[index] }));
                            });
                            shapes.append(group);
                        });

                        const detailLabel = root.querySelector('[data-detail-label]');
                        const detailDate = root.querySelector('[data-detail-date]');
                        const detailScores = root.querySelector('[data-detail-scores]');
                        const detailFeedback = root.querySelector('[data-detail-feedback]');
                        const legendButtons = [...root.querySelectorAll('[data-series-id]')];
                        const renderDetails = item => {
                            detailLabel.textContent = item.label;
                            detailDate.textContent = item.date || @js(__('public.dashboard_progress_baseline_date'));
                            detailScores.replaceChildren();
                            metrics.forEach(metric => {
                                const wrapper = document.createElement('div');
                                const term = document.createElement('dt'); term.className = 'text-xs text-zinc-500'; term.textContent = metric.label;
                                const value = document.createElement('dd'); value.className = 'mt-1 text-lg font-semibold text-zinc-900 dark:text-white'; value.textContent = `${item.scores[metric.key]}/100`;
                                wrapper.append(term, value); detailScores.append(wrapper);
                            });
                            detailFeedback.replaceChildren();
                            Object.entries(item.feedback || {}).forEach(([key, value]) => {
                                const metric = metrics.find(entry => entry.key === key);
                                if (!metric || !value) return;
                                const note = document.createElement('p'); note.className = 'text-sm leading-5 text-zinc-600 dark:text-zinc-300';
                                const strong = document.createElement('strong'); strong.className = 'block text-xs text-zinc-500'; strong.textContent = metric.label;
                                note.append(strong, document.createTextNode(value)); detailFeedback.append(note);
                            });
                        };
                        const select = id => {
                            const item = series.find(entry => entry.id === id);
                            if (!item) return;
                            legendButtons.forEach(button => {
                                const active = button.dataset.seriesId === id;
                                button.setAttribute('aria-pressed', active ? 'true' : 'false');
                                button.classList.toggle('font-bold', active);
                                const shape = shapes.querySelector(`[data-series-shape="${button.dataset.seriesId}"]`);
                                if (shape) shape.style.opacity = active ? '1' : '.68';
                            });
                            renderDetails(item);
                        };
                        legendButtons.forEach(button => button.addEventListener('click', () => select(button.dataset.seriesId)));
                        select(series[series.length - 1].id);
                    })();
                </script>
            @endif
        @endif
        @if ($supportChannelsAvailable)
            @include('public.dashboard-support')
        @endif
    </div>
</x-layouts::app>
