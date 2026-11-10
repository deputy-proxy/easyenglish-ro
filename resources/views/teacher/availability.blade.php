<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('public.teacher_availability_page_title') }} · EasyEnglish</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen bg-paper font-sans text-ink antialiased dark:bg-zinc-950 dark:text-zinc-100">
    @include('public.partials.dashboard-header', ['context' => 'teacher'])
    <main class="mx-auto max-w-[1040px] space-y-8 px-5 py-8 sm:px-7 lg:px-10 lg:py-10">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="type-eyebrow">{{ __('public.teacher_availability_page_title') }}</p>
                <h1 class="mt-2 text-3xl font-semibold">{{ __('public.teacher_availability_page_title') }}</h1>
                <p class="mt-2 text-sm text-muted dark:text-zinc-300">{{ __('public.teacher_availability_page_help') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                @if (auth()->user()->teacher_active)
                    <a href="{{ route('teacher.dashboard') }}" class="text-sm font-medium text-muted dark:text-zinc-300 underline">{{ __('public.teacher_workspace_link') }}</a>
                @endif
            </div>
        </header>

        @if (session('status'))
            <p role="status" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <div role="alert" class="rounded-xl bg-red-50 p-4 text-sm text-red-800 dark:text-red-200 dark:bg-red-950 dark:text-red-200">
                <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        @unless (auth()->user()->teacher_active)
            <p class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100">{{ __('public.teacher_inactive_notice') }}</p>
        @endunless

        <section class="dashboard-panel">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">Google Calendar</h2>
                    <p class="mt-1 text-sm text-muted dark:text-zinc-300">{{ __('public.teacher_calendar_busy_help') }}</p>
                </div>
                @if ($calendar)
                    <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $calendar->status === 'connected' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' }}">{{ $calendar->status === 'connected' ? __('public.teacher_calendar_status_connected') : __('public.teacher_calendar_status_needs_attention') }}</span>
                @endif
            </div>
            @if ($calendar)
                <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-muted dark:text-zinc-400">{{ __('public.teacher_calendar_connected_calendar') }}</dt><dd class="font-medium">{{ $calendar->calendar_email ?: $calendar->calendar_id }}</dd></div>
                    <div><dt class="text-muted dark:text-zinc-400">{{ __('public.teacher_calendar_last_sync_label') }}</dt><dd class="font-medium">{{ $calendar->last_synced_at?->setTimezone('Europe/Bucharest')->locale(app()->getLocale())->translatedFormat('j M Y, H:i') ?? __('public.teacher_calendar_not_synced') }}</dd></div>
                </dl>
                @if ($calendar->last_sync_error)
                    <p class="mt-3 text-sm text-red-700 dark:text-red-300">{{ $calendar->last_sync_error }}</p>
                @elseif (! $calendar->isFresh())
                    <p class="mt-3 text-sm text-amber-800 dark:text-amber-300">{{ __('public.teacher_calendar_sync_stale') }}</p>
                @endif
                <form method="POST" action="{{ route('teacher.google.disconnect') }}" class="mt-5">
                    @csrf @method('DELETE')
                    <button class="rounded-lg border border-line dark:border-zinc-600 px-4 py-2 text-sm font-medium">{{ __('public.teacher_calendar_disconnect') }}</button>
                </form>
            @else
                <form method="POST" action="{{ route('teacher.google.connect') }}" class="mt-5">
                    @csrf
                    <button class="rounded-lg bg-primary px-4 py-2 text-sm font-bold text-white hover:bg-primary-hover">{{ __('public.teacher_calendar_connect') }}</button>
                </form>
            @endif
            <p class="mt-4 text-xs text-muted dark:text-zinc-400">{{ __('public.teacher_calendar_freshness_help') }}</p>
        </section>

        <section class="dashboard-panel">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">{{ __('public.teacher_weekly_hours_title') }}</h2>
                    <p class="mt-1 text-sm text-muted dark:text-zinc-300">{{ __('public.teacher_weekly_hours_help') }}</p>
                </div>
                <button type="button" id="add-interval" class="rounded-lg border border-line dark:border-zinc-600 px-3 py-2 text-sm font-medium">{{ __('public.teacher_add_interval') }}</button>
            </div>
            <form method="POST" action="{{ route('teacher.availability.update') }}" class="mt-5 space-y-4">
                @csrf @method('PUT')
                <div id="intervals" class="space-y-3">
                    @foreach (old('availability', $rules->map(fn ($rule) => ['weekday' => $rule->weekday, 'starts_at' => substr((string) $rule->starts_at, 0, 5), 'ends_at' => substr((string) $rule->ends_at, 0, 5), 'is_active' => $rule->is_active])->all()) as $index => $interval)
                        <div class="availability-row grid gap-3 rounded-xl bg-paper dark:bg-zinc-800 p-3 sm:grid-cols-[1fr_1fr_1fr_auto_auto] sm:items-end">
                            <label class="text-xs font-medium text-muted dark:text-zinc-300">{{ __('public.teacher_day') }}
                                <select name="availability[{{ $index }}][weekday]" class="mt-1 w-full rounded-lg border-line dark:border-zinc-600 text-sm">
                                    @foreach ($weekdays as $number => $day)<option value="{{ $number }}" @selected((int) $interval['weekday'] === $number)>{{ $day }}</option>@endforeach
                                </select>
                            </label>
                            <label class="text-xs font-medium text-muted dark:text-zinc-300">{{ __('public.teacher_starts') }}
                                <input type="time" name="availability[{{ $index }}][starts_at]" value="{{ $interval['starts_at'] }}" class="mt-1 w-full rounded-lg border-line dark:border-zinc-600 text-sm">
                            </label>
                            <label class="text-xs font-medium text-muted dark:text-zinc-300">{{ __('public.teacher_ends') }}
                                <input type="time" name="availability[{{ $index }}][ends_at]" value="{{ $interval['ends_at'] }}" class="mt-1 w-full rounded-lg border-line dark:border-zinc-600 text-sm">
                            </label>
                            <label class="flex items-center gap-2 pb-2 text-xs text-muted dark:text-zinc-300"><input type="checkbox" name="availability[{{ $index }}][is_active]" value="1" @checked($interval['is_active'] ?? true)> {{ __('public.teacher_active') }}</label>
                            <button type="button" class="remove-interval pb-2 text-xs text-red-700 dark:text-red-300 underline">{{ __('public.teacher_remove') }}</button>
                        </div>
                    @endforeach
                    @if ($rules->isEmpty() && old('availability') === null)
                        <div class="availability-row grid gap-3 rounded-xl bg-paper dark:bg-zinc-800 p-3 sm:grid-cols-[1fr_1fr_1fr_auto_auto] sm:items-end">
                            <label class="text-xs font-medium text-muted dark:text-zinc-300">{{ __('public.teacher_day') }}<select name="availability[0][weekday]" class="mt-1 w-full rounded-lg border-line dark:border-zinc-600 text-sm">@foreach ($weekdays as $number => $day)<option value="{{ $number }}">{{ $day }}</option>@endforeach</select></label>
                            <label class="text-xs font-medium text-muted dark:text-zinc-300">{{ __('public.teacher_exception_starts') }}<input type="time" name="availability[0][starts_at]" class="mt-1 w-full rounded-lg border-line dark:border-zinc-600 text-sm"></label>
                            <label class="text-xs font-medium text-muted dark:text-zinc-300">{{ __('public.teacher_exception_ends') }}<input type="time" name="availability[0][ends_at]" class="mt-1 w-full rounded-lg border-line dark:border-zinc-600 text-sm"></label>
                            <label class="flex items-center gap-2 pb-2 text-xs text-muted dark:text-zinc-300"><input type="checkbox" name="availability[0][is_active]" value="1" checked> {{ __('public.teacher_active') }}</label>
                            <button type="button" class="remove-interval pb-2 text-xs text-red-700 dark:text-red-300 underline">{{ __('public.teacher_remove') }}</button>
                        </div>
                    @endif
                </div>
                <button class="rounded-lg bg-primary px-5 py-2.5 text-sm font-bold text-white hover:bg-primary-hover">{{ __('public.teacher_save_availability') }}</button>
            </form>
        </section>

        <section class="dashboard-panel">
            <h2 class="text-lg font-semibold">{{ __('public.teacher_exceptions_title') }}</h2>
            <p class="mt-1 text-sm text-muted dark:text-zinc-300">{{ __('public.teacher_exceptions_help') }}</p>
            <form method="POST" action="{{ route('teacher.availability.exceptions.store') }}" class="mt-5 grid gap-3 sm:grid-cols-2">
                @csrf
                <label class="text-xs font-medium text-muted dark:text-zinc-300">{{ __('public.teacher_exception_type') }}<select name="kind" required class="mt-1 w-full rounded-lg border-line dark:border-zinc-600 text-sm"><option value="blocked">{{ __('public.teacher_exception_unavailable') }}</option><option value="availability_override">{{ __('public.teacher_exception_override') }}</option></select></label>
                <label class="text-xs font-medium text-muted dark:text-zinc-300">{{ __('public.teacher_exception_reason') }}<input name="reason" maxlength="500" class="mt-1 w-full rounded-lg border-line dark:border-zinc-600 text-sm"></label>
                <label class="text-xs font-medium text-muted dark:text-zinc-300">{{ __('public.teacher_exception_starts') }}<input type="datetime-local" name="starts_at" required class="mt-1 w-full rounded-lg border-line dark:border-zinc-600 text-sm"></label>
                <label class="text-xs font-medium text-muted dark:text-zinc-300">{{ __('public.teacher_exception_ends') }}<input type="datetime-local" name="ends_at" required class="mt-1 w-full rounded-lg border-line dark:border-zinc-600 text-sm"></label>
                <button class="w-fit rounded-lg bg-stone-900 px-5 py-2.5 text-sm font-semibold text-white dark:bg-white dark:text-zinc-900 sm:col-span-2">{{ __('public.teacher_add_exception') }}</button>
            </form>
            @if ($exceptions->isNotEmpty())
                <ul class="mt-6 divide-y divide-stone-100 dark:divide-zinc-800">
                    @foreach ($exceptions as $exception)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                            <div><span class="font-medium">{{ $exception->kind === 'blocked' ? __('public.teacher_exception_unavailable') : __('public.teacher_exception_override') }}</span><span class="ml-2 text-muted dark:text-zinc-400">{{ $exception->starts_at->setTimezone('Europe/Bucharest')->locale(app()->getLocale())->translatedFormat('j M Y, H:i') }} – {{ $exception->ends_at->setTimezone('Europe/Bucharest')->locale(app()->getLocale())->translatedFormat('j M Y, H:i') }}</span>@if ($exception->reason)<p class="mt-1 text-xs text-muted dark:text-zinc-400">{{ $exception->reason }}</p>@endif</div>
                            <form method="POST" action="{{ route('teacher.availability.exceptions.destroy', $exception) }}">@csrf @method('DELETE')<button class="text-xs text-red-700 dark:text-red-300 underline">{{ __('public.teacher_remove') }}</button></form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </main>

    <template id="interval-template">
        <div class="availability-row grid gap-3 rounded-xl bg-paper dark:bg-zinc-800 p-3 sm:grid-cols-[1fr_1fr_1fr_auto_auto] sm:items-end">
            <label class="text-xs font-medium text-muted dark:text-zinc-300">{{ __('public.teacher_day') }}<select data-field="weekday" class="mt-1 w-full rounded-lg border-line dark:border-zinc-600 text-sm">@foreach ($weekdays as $number => $day)<option value="{{ $number }}">{{ $day }}</option>@endforeach</select></label>
            <label class="text-xs font-medium text-muted dark:text-zinc-300">{{ __('public.teacher_exception_starts') }}<input data-field="starts_at" type="time" class="mt-1 w-full rounded-lg border-line dark:border-zinc-600 text-sm"></label>
            <label class="text-xs font-medium text-muted dark:text-zinc-300">{{ __('public.teacher_exception_ends') }}<input data-field="ends_at" type="time" class="mt-1 w-full rounded-lg border-line dark:border-zinc-600 text-sm"></label>
            <label class="flex items-center gap-2 pb-2 text-xs text-muted dark:text-zinc-300"><input data-field="is_active" type="checkbox" value="1" checked> {{ __('public.teacher_active') }}</label>
            <button type="button" class="remove-interval pb-2 text-xs text-red-700 dark:text-red-300 underline">{{ __('public.teacher_remove') }}</button>
        </div>
    </template>
    <script>
        (() => {
            const container = document.getElementById('intervals');
            const template = document.getElementById('interval-template');
            let nextIndex = container.querySelectorAll('.availability-row').length;
            document.getElementById('add-interval').addEventListener('click', () => {
                const row = template.content.firstElementChild.cloneNode(true);
                row.querySelectorAll('[data-field]').forEach((field) => {
                    field.name = `availability[${nextIndex}][${field.dataset.field}]`;
                });
                nextIndex += 1;
                container.appendChild(row);
            });
            container.addEventListener('click', (event) => {
                if (event.target.matches('.remove-interval')) {
                    event.target.closest('.availability-row').remove();
                }
            });
        })();
    </script>
    <x-public-site-footer />
</body>
</html>
