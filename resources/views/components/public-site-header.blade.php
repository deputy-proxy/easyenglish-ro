@props(['hero' => false])

<header
    @class([
        'public-site-header sticky inset-x-0 top-0 z-50 flex h-[calc(64px+env(safe-area-inset-top,0px))] items-center justify-between gap-3 px-4 pt-[env(safe-area-inset-top,0px)] sm:px-6 md:h-[calc(72px+env(safe-area-inset-top,0px))] md:px-[clamp(22px,5.5vw,96px)]',
        'public-site-header--hero' => $hero,
        'public-site-header--standalone border-b border-line/70 bg-paper/95 backdrop-blur' => ! $hero,
    ])
    data-public-site-header
>
    <a class="shrink-0" href="{{ route('home') }}" aria-label="Easy English home">
        <img
            class="block w-[130px] [filter:brightness(0)_saturate(1)_invert(12%)_sepia(28%)_saturate(2554%)_hue-rotate(195deg)_brightness(91%)_contrast(96%)]"
            src="{{ \App\Support\VersionedAsset::url('images/logo-white.svg') }}"
            alt="EasyEnglish"
            width="192"
            height="40"
        >
    </a>
    <nav aria-label="{{ __('public.nav_label') }}" class="flex shrink-0 items-center">
        <a
            class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center overflow-hidden transition-opacity hover:opacity-75 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            href="{{ auth()->check() ? route('dashboard') : route('login') }}"
            aria-label="{{ app()->getLocale() === 'ro' ? 'Autentificare' : 'Log in' }}"
        >
            <img
                class="block h-[27px] w-[27px]"
                src="{{ \App\Support\VersionedAsset::url('images/auth-icon.svg') }}"
                alt=""
                aria-hidden="true"
                width="27"
                height="27"
            >
        </a>
    </nav>
</header>
