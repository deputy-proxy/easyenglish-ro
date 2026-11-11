<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('public.booking_bundle_title') }} · Easy English</title>
    @vite(['resources/css/app.css'])
    <style>
        body { margin: 0; background: #f7f8f3; color: #14251f; font-family: system-ui, sans-serif; }
        .bundle-booking-layout { width: min(1160px, calc(100% - 32px)); margin: 0 auto; padding: 40px 0 64px; display: grid; grid-template-columns: minmax(0, 1fr) minmax(320px, .9fr); gap: 28px; align-items: start; }
        .bundle-booking-form { min-width: 0; }
        @media (max-width: 850px) { .bundle-booking-layout { grid-template-columns: 1fr; } }

        :root { --ink: #14251f; --muted: #64736c; --green: #176b4d; --line: #e1e7df; }
        .panel { background: white; border: 1px solid var(--line); border-radius: 20px; padding: 24px; margin-bottom: 18px; }
        .panel h2 { font-size: 20px; margin: 0 0 16px; }
        .button { display: inline-flex; justify-content: center; background: var(--green); color: white; border: 0; border-radius: 99px; padding: 14px 20px; font-weight: 700; cursor: pointer; }
        .field { display: grid; gap: 7px; margin-bottom: 15px; }
        .field label { font-size: 13px; font-weight: 700; }
        .field input, .field select, .field textarea { width: 100%; border: 1px solid #cfd8d0; border-radius: 10px; padding: 12px; background: white; color: var(--ink); }
        .field textarea { min-height: 90px; }
        .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .check { display: flex; gap: 10px; font-size: 13px; line-height: 1.5; color: var(--muted); margin: 18px 0; }
        .check input { margin-top: 3px; }
        .notice { padding: 13px 15px; background: #eef4e9; border-radius: 12px; color: #385b48; font-size: 13px; line-height: 1.6; }
        .errors { color: #b42318; font-size: 13px; margin: 5px 0 14px; }
        @media (max-width: 520px) { .grid2 { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <x-public-site-header />
    <main class="bundle-booking-layout">
        <section>
            <p class="type-eyebrow">{{ __('public.booking_bundle_label') }}</p>
            <h1 class="type-section-title">{{ __('public.booking_bundle_title') }}</h1>
            <p class="type-section-copy">{{ __('public.booking_bundle_text', ['name' => $entitlement->definition->localizedName('ro'), 'credits' => $entitlement->credits_remaining + $entitlement->free_credits_remaining, 'expiry' => $entitlement->expires_at->setTimezone('Europe/Bucharest')->format('d.m.Y')]) }}</p>
            @include('components.booking.availability-section', [
                'bookingState' => ['state' => 'bundle_booking', 'display_name' => null, 'show_calendar' => true, 'booking_enabled' => true, 'show_bundles_message' => false],
                'weekStart' => $weekStart, 'compact' => false, 'showBookingModal' => false,
            ])
            <p class="mt-4"><a href="{{ route('home') }}#bundles">{{ __('public.back_booking') }}</a></p>
        </section>
        <section class="bundle-booking-form">@include('public.partials.booking-bundle-form')</section>
    </main>
    <x-public-site-footer />
    <script>
        document.addEventListener('easyenglish:availability-slot-selected', event => {
            const input = document.querySelector('#starts_at');
            if (input) input.value = event.detail.startsAt;
        });
    </script>
</body>
</html>
