<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('public.teacher_session_details') }} · EasyEnglish</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-stone-50 text-stone-900">
    <main class="mx-auto max-w-4xl space-y-8 px-5 py-10">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[.18em] text-emerald-700">EasyEnglish</p>
                <h1 class="mt-2 text-3xl font-semibold">{{ __('public.teacher_session_details') }}</h1>
                <p class="mt-2 text-sm text-stone-600">{{ __('public.teacher_booking_reference', ['reference' => $booking->public_reference]) }}</p>
            </div>
            <a href="{{ route('teacher.dashboard') }}" class="text-sm font-medium text-stone-600 underline">{{ __('public.teacher_back_to_dashboard') }}</a>
        </header>

        @if (session('status'))
            <p role="status" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <div role="alert" class="rounded-xl bg-red-50 p-4 text-sm text-red-800">
                <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <section class="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">{{ __('public.teacher_appointment') }}</h2>
                <span class="rounded-full bg-stone-100 px-3 py-1 text-xs font-semibold">{{ __('public.teacher_status_'.($booking->status === 'confirmed' && $booking->ends_at->isPast() ? 'completed' : $booking->status)) }}</span>
            </div>
            <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2">
                <div><dt class="text-stone-500">{{ __('public.teacher_learner') }}</dt><dd class="font-medium">{{ $booking->customer_name }}</dd></div>
                <div><dt class="text-stone-500">{{ __('public.teacher_english_level') }}</dt><dd class="font-medium">{{ $booking->english_level ?: __('public.teacher_level_not_set') }}</dd></div>
                <div><dt class="text-stone-500">{{ __('public.teacher_session_goal') }}</dt><dd class="font-medium">{{ $booking->session_goal ?: __('public.teacher_goal_not_set') }}</dd></div>
                <div><dt class="text-stone-500">{{ __('public.teacher_local_time') }}</dt><dd class="font-medium">{{ $booking->starts_at->setTimezone('Europe/Bucharest')->locale(app()->getLocale())->translatedFormat('j M Y, H:i') }} · {{ __('public.teacher_duration_minutes', ['minutes' => $booking->duration_minutes]) }}</dd></div>
            </dl>
            @if ($showCallLink)
                <div class="mt-6 rounded-xl bg-emerald-50 p-4">
                    <p class="text-sm font-semibold text-emerald-900">{{ __('public.teacher_call_link_ready') }}</p>
                    <a class="mt-2 inline-block break-all text-sm text-emerald-800 underline" href="{{ $callEvent->join_url }}" rel="noreferrer">{{ __('public.teacher_open_whatsapp_call') }}</a>
                </div>
            @elseif ($booking->status === 'confirmed')
                <p class="mt-5 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">{{ __('public.teacher_call_link_pending_help') }}</p>
            @endif
        </section>

        <section class="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold">{{ __('public.teacher_session_feedback') }}</h2>
            @if ($booking->status !== 'confirmed' || $booking->ends_at->isFuture())
                <p class="mt-2 text-sm text-stone-600">{{ __('public.teacher_feedback_available_after_session') }}</p>
            @else
                <form method="POST" action="{{ route('teacher.bookings.feedback', $booking->public_reference) }}" class="mt-5 space-y-5">
                    @csrf
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach (['vocabulary', 'pronunciation', 'fluency', 'grammar', 'confidence'] as $key)
                            <label class="block text-sm font-medium">{{ __('public.progress_metric_'.$key) }} <span class="text-stone-500">(0–100)</span>
                                <input type="number" name="scores[{{ $key }}]" min="0" max="100" value="{{ old('scores.'.$key, $feedback->get($key)?->current_score) }}" class="mt-1 w-full rounded-lg border-stone-300 text-sm">
                            </label>
                        @endforeach
                    </div>
                    <label class="block text-sm font-medium">{{ __('public.teacher_overall_feedback') }}
                        <textarea name="feedback" rows="5" maxlength="5000" class="mt-1 w-full rounded-lg border-stone-300 text-sm">{{ old('feedback', $feedback->get('overall')?->feedback) }}</textarea>
                    </label>
                    <button class="rounded-lg bg-stone-900 px-4 py-2 text-sm font-semibold text-white">{{ __('public.teacher_save_feedback') }}</button>
                </form>
            @endif
        </section>
    </main>
</body>
</html>
