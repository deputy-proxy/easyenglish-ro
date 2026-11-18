<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('public.booking_landing_title') }} · Easy English</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-surface-subtle text-ink">
    <header class="flex h-16 items-center justify-between gap-4 px-5 sm:px-8 md:px-14">
        <a class="shrink-0" href="{{ route('home') }}" aria-label="Easy English home">
            <img class="block w-[130px] [filter:brightness(0)_saturate(1)_invert(12%)_sepia(28%)_saturate(2554%)_hue-rotate(195deg)_brightness(91%)_contrast(96%)]" src="{{ \App\Support\VersionedAsset::url('images/logo-white.svg') }}" alt="EasyEnglish" width="192" height="40">
        </a>
    </header>
    <main class="py-6 md:py-10">
        @include('components.booking.availability-section', ['compact' => true] + compact('bookingState', 'weekStart', 'contactsVerified', 'verification', 'eligibility', 'phoneAlreadyVerified', 'whatsappConsent', 'bookingFormData', 'bundles'))
        @if($bookingState['show_bundles_message'])
            <section id="bundles" class="mx-auto mt-8 w-[calc(100%-2.5rem)] max-w-[1160px] rounded-3xl bg-white p-6 shadow-sm sm:p-9">
                <div class="max-w-2xl">
                    <p class="type-eyebrow">{{ __('public.packages_eyebrow') }}</p>
                    <h2 class="type-section-title mt-3">{{ $bookingState['display_name'] ? __('public.bundles_personalized_title', ['name' => $bookingState['display_name']]) : __('public.packages_title') }}</h2>
                    <p class="type-section-copy mt-3">{{ $bookingState['display_name'] ? __('public.bundles_personalized_text') : __('public.packages_text') }}</p>
                </div>
                <div class="mt-6 grid gap-4 md:grid-cols-3">
                    @foreach($bundles as $bundle)
                        @php($price = $bundle->priceBreakdown())
                        <article class="flex flex-col rounded-2xl border border-line p-5">
                            <h3 class="text-lg font-bold">{{ $bundle->localizedName() }}</h3>
                            <p class="mt-2 text-sm text-muted">{{ $bundle->localizedValue($bundle->short_description) }}</p>
                            <p class="mt-auto pt-4 text-2xl font-extrabold">{{ number_format($price['current_price_ron'], 0, ',', '.') }} RON</p>
                            <a class="mt-4 inline-flex min-h-11 items-center justify-center rounded-full bg-primary px-5 py-3 text-sm font-bold text-white" href="{{ route('purchase', $bundle) }}">{{ __('public.package_cta') }}</a>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif
    </main>
    <x-public-site-footer />
</body>
</html>
