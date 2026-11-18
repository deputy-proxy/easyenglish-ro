<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-paper font-sans text-ink antialiased dark:bg-zinc-950 dark:text-zinc-100">
    @include('public.partials.dashboard-header', ['context' => 'customer'])
    <main class="mx-auto w-full max-w-[1240px] space-y-7 px-5 py-8 sm:px-7 lg:px-10 lg:py-10">
        <header class="flex flex-col gap-3 border-b border-line pb-7 dark:border-zinc-800 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="type-eyebrow">{{ __('public.dashboard_account') }}</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink dark:text-white sm:text-4xl">{{ $title ?? __('Settings') }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted dark:text-zinc-400">{{ __('public.dashboard_account_help') }}</p>
            </div>
            <a href="{{ route('dashboard', ['view' => 'account']) }}" class="inline-flex min-h-11 items-center justify-center self-start rounded-full border border-ink px-5 py-2.5 text-sm font-bold text-ink transition hover:bg-ink hover:text-white dark:border-zinc-500 dark:text-zinc-100 dark:hover:bg-zinc-800 sm:self-auto">{{ __('public.dashboard_back_to_overview') }}</a>
        </header>
        @include('public.partials.customer-dashboard-navigation', ['activeView' => 'account'])
        {{ $slot }}
    </main>
    @persist('toast')
        <flux:toast.group>
            <flux:toast />
        </flux:toast.group>
    @endpersist
    @fluxScripts
    <x-public-site-footer />
</body>
</html>
