<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ $page === 'privacy' ? __('public.privacy_intro') : __('public.terms_intro') }}">
    <title>{{ $page === 'privacy' ? __('public.privacy_title') : __('public.terms_title') }} · Easy English</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-paper font-sans text-ink antialiased">
    <div class="min-h-screen">
        <header class="public-site-header sticky inset-x-0 top-0 z-50 flex h-[calc(76px+env(safe-area-inset-top,0px))] items-center justify-between gap-3 border-b border-line/70 bg-paper/95 px-4 pt-[env(safe-area-inset-top,0px)] backdrop-blur sm:px-6 md:h-[calc(88px+env(safe-area-inset-top,0px))] md:px-[clamp(22px,5.5vw,96px)]">
            <a class="shrink-0" href="{{ route('home') }}" aria-label="Easy English home">
                <img class="block w-[130px] [filter:brightness(0)_saturate(1)_invert(12%)_sepia(28%)_saturate(2554%)_hue-rotate(195deg)_brightness(91%)_contrast(96%)]" src="{{ \App\Support\VersionedAsset::url('images/logo-white.svg') }}" alt="EasyEnglish" width="192" height="40">
            </a>
            <a class="inline-flex min-h-9 items-center justify-center gap-2 rounded-[6px] border border-ink px-3 py-1.5 text-[11px] leading-none font-bold text-ink transition-colors hover:bg-ink hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" href="{{ url('/admin/login') }}">
                <img class="size-4 shrink-0" src="{{ \App\Support\VersionedAsset::url('images/easyenglish-symbol-blue.svg') }}" alt="" aria-hidden="true" width="16" height="16">
                <span>{{ app()->getLocale() === 'ro' ? 'Autentificare' : 'Log in' }}</span>
            </a>
        </header>

        <main class="mx-auto w-[calc(100%-2.5rem)] max-w-[1160px] py-12 sm:py-16 md:py-[82px]">
            <div class="mx-auto max-w-[820px]">
                <a href="{{ route('home') }}" class="mb-8 inline-flex items-center gap-2 text-sm font-bold text-primary transition-colors hover:text-primary-hover">
                    <span aria-hidden="true">←</span>
                    {{ __('public.back_home') }}
                </a>

                <div class="mb-10 border-b border-line pb-8 md:mb-12 md:pb-10">
                    <p class="mb-[18px] type-eyebrow">{{ $page === 'privacy' ? (app()->getLocale() === 'ro' ? 'CONFIDENȚIALITATE' : 'PRIVACY') : (app()->getLocale() === 'ro' ? 'TERMENI ȘI CONDIȚII' : 'TERMS & CONDITIONS') }}</p>
                    <h1 class="type-section-title mb-5">{{ $page === 'privacy' ? __('public.privacy_title') : __('public.terms_title') }}</h1>
                    <p class="type-section-copy max-w-[68ch]">{{ $page === 'privacy' ? __('public.privacy_intro') : __('public.terms_intro') }}</p>
                </div>

                <div class="space-y-0">
                    @if ($page === 'privacy')
                        <section class="border-b border-line py-7 first:pt-0 md:py-9">
                            <h2 class="type-card-title mb-3">{{ __('public.privacy_data_title') }}</h2>
                            <p class="type-section-copy max-w-[72ch]">{{ __('public.privacy_data_text') }}</p>
                        </section>
                        <section class="border-b border-line py-7 md:py-9">
                            <h2 class="type-card-title mb-3">{{ __('public.privacy_use_title') }}</h2>
                            <p class="type-section-copy max-w-[72ch]">{{ __('public.privacy_use_text') }}</p>
                        </section>
                        <section class="border-b border-line py-7 md:py-9">
                            <h2 class="type-card-title mb-3">{{ __('public.privacy_retention_title') }}</h2>
                            <p class="type-section-copy max-w-[72ch]">{{ __('public.privacy_retention_text') }}</p>
                        </section>
                    @else
                        <section class="border-b border-line py-7 first:pt-0 md:py-9">
                            <h2 class="type-card-title mb-3">{{ __('public.terms_24_title') }}</h2>
                            <p class="type-section-copy max-w-[72ch]">{{ __('public.terms_24_text') }}</p>
                        </section>
                        <section class="border-b border-line py-7 md:py-9">
                            <h2 class="type-card-title mb-3">{{ __('public.terms_12_title') }}</h2>
                            <p class="type-section-copy max-w-[72ch]">{{ __('public.terms_12_text') }}</p>
                        </section>
                        <section class="border-b border-line py-7 md:py-9">
                            <h2 class="type-card-title mb-3">{{ __('public.terms_late_title') }}</h2>
                            <p class="type-section-copy max-w-[72ch]">{{ __('public.terms_late_text') }}</p>
                        </section>
                        <section class="border-b border-line py-7 md:py-9">
                            <h2 class="type-card-title mb-3">{{ __('public.terms_teacher_title') }}</h2>
                            <p class="type-section-copy max-w-[72ch]">{{ __('public.terms_teacher_text') }}</p>
                            <p class="type-section-copy mt-4 max-w-[72ch]">{{ __('public.terms_refund_text') }}</p>
                        </section>
                    @endif
                </div>
            </div>
        </main>

        <footer class="border-t border-line bg-surface-subtle">
            <div class="mx-auto flex w-[calc(100%-2.5rem)] max-w-[1160px] flex-col gap-5 py-7 text-sm text-muted sm:flex-row sm:items-center sm:justify-between">
                <a href="{{ route('home') }}" class="font-bold tracking-tight text-ink">EasyEnglish</a>
                <nav class="flex flex-wrap items-center gap-x-6 gap-y-2" aria-label="{{ app()->getLocale() === 'ro' ? 'Linkuri juridice' : 'Legal links' }}">
                    <a class="transition-colors hover:text-primary" href="{{ route('terms') }}">{{ __('public.terms_title') }}</a>
                    <a class="transition-colors hover:text-primary" href="{{ route('privacy') }}">{{ __('public.privacy_title') }}</a>
                </nav>
                <form action="{{ route('locale.set') }}" method="POST" class="flex items-center gap-2">
                    @csrf
                    <label for="policy-locale" class="sr-only">{{ app()->getLocale() === 'ro' ? 'Limba' : 'Language' }}</label>
                    <select id="policy-locale" name="locale" onchange="this.form.submit()" class="rounded-md border border-line bg-white px-2.5 py-2 text-xs font-semibold text-ink">
                        <option value="ro" @selected(app()->getLocale() === 'ro')>Română</option>
                        <option value="en" @selected(app()->getLocale() === 'en')>English</option>
                    </select>
                </form>
            </div>
        </footer>
    </div>
</body>
</html>
