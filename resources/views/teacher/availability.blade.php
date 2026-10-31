<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Teacher availability · EasyEnglish</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-stone-50 text-stone-900">
    <main class="mx-auto max-w-4xl space-y-8 px-5 py-10">
        <header class="flex items-center justify-between gap-4">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[.18em] text-emerald-700">EasyEnglish</p>
                <h1 class="mt-2 text-3xl font-semibold">Your availability</h1>
                <p class="mt-2 text-sm text-stone-600">Manage your weekly working hours and calendar connection. Times use Europe/Bucharest.</p>
            </div>
            <a href="{{ route('dashboard') }}" class="text-sm font-medium text-stone-600 underline">Dashboard</a>
        </header>

        @if (session('status'))
            <p role="status" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <div role="alert" class="rounded-xl bg-red-50 p-4 text-sm text-red-800">
                <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        @unless (auth()->user()->teacher_active)
            <p class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900">Your teacher account is currently inactive. Your schedule will not appear in public availability until an administrator activates it.</p>
        @endunless

        <section class="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">Google Calendar</h2>
                    <p class="mt-1 text-sm text-stone-600">Busy events block your bookable time. Event titles and descriptions are not stored.</p>
                </div>
                @if ($calendar)
                    <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $calendar->status === 'connected' ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' }}">{{ ucfirst($calendar->status) }}</span>
                @endif
            </div>
            @if ($calendar)
                <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-stone-500">Connected calendar</dt><dd class="font-medium">{{ $calendar->calendar_email ?: $calendar->calendar_id }}</dd></div>
                    <div><dt class="text-stone-500">Last successful sync</dt><dd class="font-medium">{{ $calendar->last_synced_at?->setTimezone('Europe/Bucharest')->format('d M Y, H:i') ?? 'Not synced yet' }}</dd></div>
                </dl>
                @if ($calendar->last_sync_error)
                    <p class="mt-3 text-sm text-red-700">{{ $calendar->last_sync_error }}</p>
                @elseif (! $calendar->isFresh())
                    <p class="mt-3 text-sm text-amber-800">Your calendar sync is pending or stale. You will not appear in public availability until a successful sync completes.</p>
                @endif
                <form method="POST" action="{{ route('teacher.google.disconnect') }}" class="mt-5">
                    @csrf @method('DELETE')
                    <button class="rounded-lg border border-stone-300 px-4 py-2 text-sm font-medium">Disconnect calendar</button>
                </form>
            @else
                <form method="POST" action="{{ route('teacher.google.connect') }}" class="mt-5">
                    @csrf
                    <button class="rounded-lg bg-stone-900 px-4 py-2 text-sm font-semibold text-white">Connect Google Calendar</button>
                </form>
            @endif
            <p class="mt-4 text-xs text-stone-500">Calendar availability is considered verified only when the last successful sync is no more than 10 minutes old. Reconciliation runs every five minutes.</p>
        </section>

        <section class="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">Weekly working hours</h2>
                    <p class="mt-1 text-sm text-stone-600">Add one or more intervals for each day. Overnight intervals are supported.</p>
                </div>
                <button type="button" id="add-interval" class="rounded-lg border border-stone-300 px-3 py-2 text-sm font-medium">Add interval</button>
            </div>
            <form method="POST" action="{{ route('teacher.availability.update') }}" class="mt-5 space-y-4">
                @csrf @method('PUT')
                <div id="intervals" class="space-y-3">
                    @foreach (old('availability', $rules->map(fn ($rule) => ['weekday' => $rule->weekday, 'starts_at' => substr((string) $rule->starts_at, 0, 5), 'ends_at' => substr((string) $rule->ends_at, 0, 5), 'is_active' => $rule->is_active])->all()) as $index => $interval)
                        <div class="availability-row grid gap-3 rounded-xl bg-stone-50 p-3 sm:grid-cols-[1fr_1fr_1fr_auto_auto] sm:items-end">
                            <label class="text-xs font-medium text-stone-600">Day
                                <select name="availability[{{ $index }}][weekday]" class="mt-1 w-full rounded-lg border-stone-300 text-sm">
                                    @foreach ($weekdays as $number => $day)<option value="{{ $number }}" @selected((int) $interval['weekday'] === $number)>{{ $day }}</option>@endforeach
                                </select>
                            </label>
                            <label class="text-xs font-medium text-stone-600">Starts
                                <input type="time" name="availability[{{ $index }}][starts_at]" value="{{ $interval['starts_at'] }}" class="mt-1 w-full rounded-lg border-stone-300 text-sm">
                            </label>
                            <label class="text-xs font-medium text-stone-600">Ends
                                <input type="time" name="availability[{{ $index }}][ends_at]" value="{{ $interval['ends_at'] }}" class="mt-1 w-full rounded-lg border-stone-300 text-sm">
                            </label>
                            <label class="flex items-center gap-2 pb-2 text-xs text-stone-600"><input type="checkbox" name="availability[{{ $index }}][is_active]" value="1" @checked($interval['is_active'] ?? true)> Active</label>
                            <button type="button" class="remove-interval pb-2 text-xs text-red-700 underline">Remove</button>
                        </div>
                    @endforeach
                    @if ($rules->isEmpty() && old('availability') === null)
                        <div class="availability-row grid gap-3 rounded-xl bg-stone-50 p-3 sm:grid-cols-[1fr_1fr_1fr_auto_auto] sm:items-end">
                            <label class="text-xs font-medium text-stone-600">Day<select name="availability[0][weekday]" class="mt-1 w-full rounded-lg border-stone-300 text-sm">@foreach ($weekdays as $number => $day)<option value="{{ $number }}">{{ $day }}</option>@endforeach</select></label>
                            <label class="text-xs font-medium text-stone-600">Starts<input type="time" name="availability[0][starts_at]" class="mt-1 w-full rounded-lg border-stone-300 text-sm"></label>
                            <label class="text-xs font-medium text-stone-600">Ends<input type="time" name="availability[0][ends_at]" class="mt-1 w-full rounded-lg border-stone-300 text-sm"></label>
                            <label class="flex items-center gap-2 pb-2 text-xs text-stone-600"><input type="checkbox" name="availability[0][is_active]" value="1" checked> Active</label>
                            <button type="button" class="remove-interval pb-2 text-xs text-red-700 underline">Remove</button>
                        </div>
                    @endif
                </div>
                <button class="rounded-lg bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white">Save weekly availability</button>
            </form>
        </section>

        <section class="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold">Date-specific exceptions</h2>
            <p class="mt-1 text-sm text-stone-600">Block time off or replace the weekly schedule for a specific date/range.</p>
            <form method="POST" action="{{ route('teacher.availability.exceptions.store') }}" class="mt-5 grid gap-3 sm:grid-cols-2">
                @csrf
                <label class="text-xs font-medium text-stone-600">Type<select name="kind" required class="mt-1 w-full rounded-lg border-stone-300 text-sm"><option value="blocked">Unavailable</option><option value="availability_override">Weekly schedule override</option></select></label>
                <label class="text-xs font-medium text-stone-600">Reason<input name="reason" maxlength="500" class="mt-1 w-full rounded-lg border-stone-300 text-sm"></label>
                <label class="text-xs font-medium text-stone-600">Starts<input type="datetime-local" name="starts_at" required class="mt-1 w-full rounded-lg border-stone-300 text-sm"></label>
                <label class="text-xs font-medium text-stone-600">Ends<input type="datetime-local" name="ends_at" required class="mt-1 w-full rounded-lg border-stone-300 text-sm"></label>
                <button class="w-fit rounded-lg bg-stone-900 px-5 py-2.5 text-sm font-semibold text-white sm:col-span-2">Add exception</button>
            </form>
            @if ($exceptions->isNotEmpty())
                <ul class="mt-6 divide-y divide-stone-100">
                    @foreach ($exceptions as $exception)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                            <div><span class="font-medium">{{ $exception->kind === 'blocked' ? 'Unavailable' : 'Weekly schedule override' }}</span><span class="ml-2 text-stone-500">{{ $exception->starts_at->setTimezone('Europe/Bucharest')->format('d M Y, H:i') }} – {{ $exception->ends_at->setTimezone('Europe/Bucharest')->format('d M Y, H:i') }}</span>@if ($exception->reason)<p class="mt-1 text-xs text-stone-500">{{ $exception->reason }}</p>@endif</div>
                            <form method="POST" action="{{ route('teacher.availability.exceptions.destroy', $exception) }}">@csrf @method('DELETE')<button class="text-xs text-red-700 underline">Remove</button></form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </main>

    <template id="interval-template">
        <div class="availability-row grid gap-3 rounded-xl bg-stone-50 p-3 sm:grid-cols-[1fr_1fr_1fr_auto_auto] sm:items-end">
            <label class="text-xs font-medium text-stone-600">Day<select data-field="weekday" class="mt-1 w-full rounded-lg border-stone-300 text-sm">@foreach ($weekdays as $number => $day)<option value="{{ $number }}">{{ $day }}</option>@endforeach</select></label>
            <label class="text-xs font-medium text-stone-600">Starts<input data-field="starts_at" type="time" class="mt-1 w-full rounded-lg border-stone-300 text-sm"></label>
            <label class="text-xs font-medium text-stone-600">Ends<input data-field="ends_at" type="time" class="mt-1 w-full rounded-lg border-stone-300 text-sm"></label>
            <label class="flex items-center gap-2 pb-2 text-xs text-stone-600"><input data-field="is_active" type="checkbox" value="1" checked> Active</label>
            <button type="button" class="remove-interval pb-2 text-xs text-red-700 underline">Remove</button>
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
</body>
</html>
